<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Enums\SourceEnvironment;
use App\Exceptions\UnnormalizedServiceApprovalException;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Client;
use App\Models\Location;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Services\PostageSources\ObservedServiceMapper;
use App\Services\PostageSources\ServiceApprovalGate;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Where a human says "this Amazon service is our Ground Advantage".
 *
 * The counterpart to {@see UnmappedShippingReferences}, and modeled on it
 * deliberately: an import that cannot resolve a channel's shipping string does
 * not reject the shipment, it records the string and offers it here. A postage
 * source naming a service we have no row for behaves the same way.
 *
 * Two things this page does *not* do, both from ADR-0003. It carries no
 * navigation badge and raises nothing: an observation nobody promotes stays
 * permanently human-selectable rather than nagging from a queue (decision 8).
 * And it never invents catalog rows on the operator's behalf — authoring a
 * `Carrier` or a `CarrierService` is a separate, admin-gated action with a form
 * in front of it (decision 2).
 */
class UnmappedObservedServices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Map Carrier Services';

    protected static ?string $title = 'Map Carrier Services';

    protected static UnitEnum|string|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.pages.unmapped-observed-services';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Manager);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ObservedService::query()
                    ->with('carrierService.carrier')
                    ->select('observed_services.*')
                    // Correlated subqueries rather than a lookup per row:
                    // approvals have no foreign key to hang a relation off,
                    // being keyed on the identity rather than on the sighting.
                    // Two of them, because two questions are asked — what this
                    // row's world has approved, and what unmapping would
                    // withdraw, which reaches every world.
                    ->selectSub(static::approvalCountQuery(), 'environment_approvals_count')
                    ->selectSub(static::serviceApprovalCountQuery(), 'service_approvals_count')
            )
            ->defaultSort('last_seen_at', 'desc')
            ->groups([
                Group::make('source')
                    ->label('Source')
                    ->getTitleFromRecordUsing(fn (ObservedService $record): string => Str::headline($record->source)),
            ])
            ->defaultGroup('source')
            ->columns([
                Tables\Columns\TextColumn::make('external_carrier_name')
                    ->label('Carrier')
                    ->state(fn (ObservedService $record): string => $record->external_carrier_name ?? $record->external_carrier_id)
                    ->description(fn (ObservedService $record): string => $record->external_carrier_id)
                    ->searchable(['external_carrier_name', 'external_carrier_id'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('external_service_name')
                    ->label('Service')
                    ->state(fn (ObservedService $record): string => $record->external_service_name ?? $record->external_service_id)
                    ->description(fn (ObservedService $record): string => $record->external_service_id)
                    ->searchable(['external_service_name', 'external_service_id'])
                    ->sortable()
                    // Grouped by source, so these render per source: how many
                    // distinct identities that source has shown us, against how
                    // many times it has shown them.
                    ->summarize(
                        Tables\Columns\Summarizers\Count::make()->label('Services'),
                    ),
                Tables\Columns\TextColumn::make('environment')
                    ->label('Environment')
                    ->badge()
                    ->formatStateUsing(fn (SourceEnvironment $state): string => $state->label())
                    ->color(fn (SourceEnvironment $state): string => $state === SourceEnvironment::Sandbox ? 'warning' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('marketplace')
                    ->label('Marketplace')
                    // Stored as '' rather than null for sources that have no
                    // marketplace, so the empty case needs saying explicitly.
                    ->state(fn (ObservedService $record): ?string => $record->marketplace !== '' ? $record->marketplace : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('last_eligible_at')
                    ->label('Offered')
                    ->tooltip('Whether this service has ever been offered as buyable, rather than only listed as ineligible.')
                    ->boolean()
                    ->state(fn (ObservedService $record): bool => $record->hasBeenEligible()),
                Tables\Columns\TextColumn::make('observation_count')
                    ->label('Times seen')
                    ->numeric()
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Sum::make()->label('Observations'),
                    ),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime('M j, Y g:i A', timezone: Location::timezone())
                    ->sortable(),
                Tables\Columns\TextColumn::make('carrierService.name')
                    ->label('Mapped to')
                    ->description(fn (ObservedService $record): ?string => $record->carrierService?->carrier?->name)
                    ->placeholder('Unmapped'),
                Tables\Columns\TextColumn::make('environment_approvals_count')
                    ->label('Approved for')
                    ->badge()
                    ->color('success')
                    ->tooltip('Clients whose automated shipping may buy this service, in this environment. Everyone else can still choose it by hand on the Ship page.')
                    ->state(fn (ObservedService $record): ?string => $record->environment_approvals_count > 0
                        ? trans_choice(':count client|:count clients', $record->environment_approvals_count, ['count' => $record->environment_approvals_count])
                        : null)
                    ->placeholder('Attended only'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('mapped')
                    ->label('Mapping')
                    ->placeholder('All')
                    ->trueLabel('Mapped')
                    ->falseLabel('Unmapped')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('carrier_service_id'),
                        false: fn ($query) => $query->whereNull('carrier_service_id'),
                        blank: fn ($query) => $query,
                    )
                    ->default(false),
                Tables\Filters\SelectFilter::make('environment')
                    ->label('Environment')
                    ->options(fn (): array => collect(SourceEnvironment::cases())
                        ->mapWithKeys(fn (SourceEnvironment $environment): array => [$environment->value => $environment->label()])
                        ->all()),
                Tables\Filters\TernaryFilter::make('approved')
                    ->label('Approval')
                    ->placeholder('All')
                    ->trueLabel('Approved for automation')
                    ->falseLabel('Attended only')
                    ->queries(
                        true: fn ($query) => $query->whereExists(static::approvalExistsQuery()),
                        false: fn ($query) => $query->whereNotExists(static::approvalExistsQuery()),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->emptyStateHeading('Nothing observed yet')
            ->emptyStateDescription('Services a postage source reports appear here after a rate quote. Leaving one unmapped is fine — nothing depends on it being mapped.')
            ->recordActions([
                Actions\Action::make('assign')
                    ->label('Assign')
                    ->icon('heroicon-o-link')
                    ->modalDescription('Alias this observed service onto a service already in the catalog. Nothing new is created.')
                    ->schema([
                        Forms\Components\Select::make('carrier_service_id')
                            ->label('Carrier Service')
                            ->options(fn (): array => static::carrierServiceOptions())
                            ->default(fn (ObservedService $record): ?int => $record->carrier_service_id)
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (ObservedService $record, array $data): void {
                        $carrierService = CarrierService::findOrFail($data['carrier_service_id']);

                        $mapped = app(ObservedServiceMapper::class)->map($record, $carrierService);

                        Notification::make()
                            ->success()
                            ->title("Mapped to {$carrierService->name}")
                            ->body(static::coverage($mapped))
                            ->send();
                    }),

                Actions\Action::make('author')
                    ->label('Author service')
                    ->icon('heroicon-o-plus-circle')
                    ->color('gray')
                    ->modalHeading('Author a carrier service')
                    ->modalDescription('For a service the catalog has no row for. This creates a real Carrier Service — and, if you create one, a real Carrier — which then behaves like any other authored configuration.')
                    ->modalSubmitActionLabel('Create and map')
                    // Aliasing onto an existing service is a manager's job; minting
                    // catalog identities is not, and CarrierServicePolicy already
                    // says so for the resource that does it the ordinary way.
                    ->visible(fn (): bool => auth()->user()->can('create', CarrierService::class))
                    ->schema([
                        Forms\Components\Select::make('carrier_id')
                            ->label('Carrier')
                            ->options(fn (): array => Carrier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn (ObservedService $record): ?int => static::matchingCarrierId($record))
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Carrier name')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data): int => Carrier::create([
                                'name' => $data['name'],
                                'active' => true,
                            ])->getKey())
                            ->helperText('No row for this carrier? Create one — this is catalog authorship, not discovery.'),
                        Forms\Components\TextInput::make('service_code')
                            ->label('Service code')
                            ->default(fn (ObservedService $record): string => $record->external_service_id)
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('name')
                            ->label('Service name')
                            ->default(fn (ObservedService $record): string => $record->external_service_name ?? $record->external_service_id)
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('can_ship_to_po_boxes')
                            ->label('Can ship to PO Boxes')
                            ->default(false),
                        Forms\Components\Toggle::make('can_ship_to_military_addresses')
                            ->label('Can ship to military addresses')
                            ->default(false),
                    ])
                    ->action(function (ObservedService $record, array $data): void {
                        $carrier = Carrier::findOrFail($data['carrier_id']);

                        $mapped = app(ObservedServiceMapper::class)->promote(
                            observation: $record,
                            carrier: $carrier,
                            serviceCode: $data['service_code'],
                            serviceName: $data['name'],
                            canShipToPoBoxes: (bool) $data['can_ship_to_po_boxes'],
                            canShipToMilitaryAddresses: (bool) $data['can_ship_to_military_addresses'],
                        );

                        Notification::make()
                            ->success()
                            ->title("Created {$carrier->name} {$data['name']}")
                            ->body(static::coverage($mapped))
                            ->send();
                    }),

                Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->modalHeading('Approve for automated shipping')
                    ->modalDescription(fn (ObservedService $record): string => "Ticked clients' automated shipping — auto-ship, batch ship, shipping rules — may buy {$record->displayName()} in {$record->environment->label()}. Unticked clients keep it as something a packer chooses by hand, having seen the price.")
                    ->modalSubmitActionLabel('Save approvals')
                    // Mapped first: ADR-0003 decision 2 puts normalization
                    // before approval, so the button is not there to press on
                    // an identity nobody has named. The gate enforces the same
                    // thing again, because a form is not a guarantee.
                    ->visible(fn (ObservedService $record): bool => $record->isMapped()
                        && auth()->user()->can('create', ServiceApproval::class))
                    ->schema([
                        Forms\Components\CheckboxList::make('client_ids')
                            ->label('Clients')
                            ->options(fn (): array => static::clientOptions())
                            ->default(fn (ObservedService $record): array => app(ServiceApprovalGate::class)
                                ->approvedClientIds($record)
                                ->all())
                            ->bulkToggleable()
                            ->helperText('Unticking withdraws approval; nothing else changes. Approval covers this environment only — sandbox and production identifiers differ, so one never speaks for the other.'),
                    ])
                    ->action(function (ObservedService $record, array $data): void {
                        $approver = auth()->user();

                        try {
                            $result = app(ServiceApprovalGate::class)->syncClients(
                                observation: $record,
                                clientIds: $data['client_ids'] ?? [],
                                approver: $approver,
                            );
                        } catch (UnnormalizedServiceApprovalException $e) {
                            // Someone unmapped it while this form was open.
                            Notification::make()->danger()->title('Not approved')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Approvals saved')
                            ->body(static::approvalSummary($result, $record))
                            ->send();
                    }),

                Actions\Action::make('unmap')
                    ->label('Unmap')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ObservedService $record): string => 'The observation stays on file and stays selectable by a person. Only the mapping is removed; no catalog rows are deleted.'
                        .($record->service_approvals_count > 0
                            ? ' '.trans_choice(
                                'This also withdraws :count client approval for automated shipping, in every environment — a service nobody has named cannot be one automation is allowed to buy.|This also withdraws :count client approvals for automated shipping, in every environment — a service nobody has named cannot be one automation is allowed to buy.',
                                $record->service_approvals_count,
                                ['count' => $record->service_approvals_count],
                            )
                            : ' Nothing has been approved for automated shipping, so nothing is withdrawn.'))
                    // Unmapping withdraws every approval of the service, which
                    // is an admin's act — see ServiceApprovalPolicy::deleteAny().
                    // A manager keeps the button for a service nobody has
                    // approved, which is the ordinary case and their job.
                    ->visible(fn (ObservedService $record): bool => $record->isMapped()
                        && ($record->service_approvals_count === 0
                            || auth()->user()->can('deleteAny', ServiceApproval::class)))
                    ->action(function (ObservedService $record): void {
                        $result = app(ObservedServiceMapper::class)->unmap($record);

                        Notification::make()
                            ->success()
                            ->title('Mapping removed')
                            ->body(trim(implode(' ', array_filter([
                                static::coverage($result['observations']),
                                $result['approvals'] > 0
                                    ? trans_choice(':count client approval withdrawn.|:count client approvals withdrawn.', $result['approvals'], ['count' => $result['approvals']])
                                    : null,
                            ]))) ?: null)
                            ->send();
                    }),
            ]);
    }

    /**
     * Carrier services, labelled the way a person picking one needs to read
     * them — "USPS — Ground Advantage", not "Ground Advantage" three times.
     *
     * @return array<int, string>
     */
    protected static function carrierServiceOptions(): array
    {
        return CarrierService::query()
            ->with('carrier')
            ->get()
            ->sortBy(fn (CarrierService $service): string => $service->carrier->name.' '.$service->name)
            ->mapWithKeys(fn (CarrierService $service): array => [
                $service->getKey() => trim($service->carrier->name.' — '.$service->name),
            ])
            ->all();
    }

    /**
     * The carrier row this observation probably belongs to, as a starting point
     * a human then confirms or changes. A guess in a form default, not a match.
     */
    protected static function matchingCarrierId(ObservedService $record): ?int
    {
        $name = Str::lower(Str::squish($record->external_carrier_name ?? $record->external_carrier_id));

        return Carrier::query()
            ->get()
            ->first(fn (Carrier $carrier): bool => Str::lower(Str::squish($carrier->name)) === $name)
            ?->getKey();
    }

    /**
     * Clients, all of them, including inactive ones.
     *
     * Filtering the list to active clients would make the form lie: the
     * checkbox list submits what it shows, so an approval held by a client that
     * happens to be inactive would be silently withdrawn by anyone saving this
     * form for an unrelated reason.
     *
     * @return array<int, string>
     */
    protected static function clientOptions(): array
    {
        return Client::query()
            ->orderBy('name')
            ->get(['id', 'name', 'active'])
            ->mapWithKeys(fn (Client $client): array => [
                $client->getKey() => $client->name.($client->active ? '' : ' (inactive)'),
            ])
            ->all();
    }

    /**
     * How many approvals exist for the identity a row names, in the world it
     * was seen in.
     *
     * A correlated subquery rather than a relation: `service_approvals` is
     * keyed on the service identity, not on the sighting, so there is no
     * foreign key between the two tables to hang an Eloquent relation on. The
     * join columns are exactly {@see ServiceApproval::scopeForService()}'s
     * four.
     *
     * @return Builder<ServiceApproval>
     */
    protected static function approvalCountQuery(): Builder
    {
        return static::approvalExistsQuery()->selectRaw('count(*)');
    }

    /**
     * How many approvals unmapping a row would withdraw — every world, not
     * just this row's.
     *
     * `ObservedServiceMapper::unmap()` clears the mapping across environments
     * and revokes across environments with it, so the count that decides who
     * may press the button has to be the wider one. The narrower count is what
     * the column shows, because an approval only ever authorizes spending in
     * its own world.
     *
     * @return Builder<ServiceApproval>
     */
    protected static function serviceApprovalCountQuery(): Builder
    {
        return ServiceApproval::query()
            ->selectRaw('count(*)')
            ->whereColumn('service_approvals.source', 'observed_services.source')
            ->whereColumn('service_approvals.external_carrier_id', 'observed_services.external_carrier_id')
            ->whereColumn('service_approvals.external_service_id', 'observed_services.external_service_id');
    }

    /**
     * The same correlation, for the approval filter.
     *
     * @return Builder<ServiceApproval>
     */
    protected static function approvalExistsQuery(): Builder
    {
        return ServiceApproval::query()
            ->whereColumn('service_approvals.source', 'observed_services.source')
            ->whereColumn('service_approvals.environment', 'observed_services.environment')
            ->whereColumn('service_approvals.external_carrier_id', 'observed_services.external_carrier_id')
            ->whereColumn('service_approvals.external_service_id', 'observed_services.external_service_id');
    }

    /**
     * What a save actually did, in the operator's terms — approvals are the one
     * thing on this page that spends money, so "saved" on its own is not enough.
     *
     * @param  array{granted: int, revoked: int}  $result
     */
    protected static function approvalSummary(array $result, ObservedService $record): string
    {
        $environment = $record->environment->label();

        if ($result['granted'] === 0 && $result['revoked'] === 0) {
            return "No change. {$environment} approvals are as they were.";
        }

        return trim(implode(' ', array_filter([
            $result['granted'] > 0
                ? trans_choice(":count client approved for {$environment}.|:count clients approved for {$environment}.", $result['granted'], ['count' => $result['granted']])
                : null,
            $result['revoked'] > 0
                ? trans_choice(':count approval withdrawn.|:count approvals withdrawn.', $result['revoked'], ['count' => $result['revoked']])
                : null,
        ])));
    }

    /**
     * One mapping can cover the same identity in more than one environment or
     * marketplace, so say when it did rather than leaving it a surprise.
     */
    protected static function coverage(int $observations): ?string
    {
        return $observations > 1
            ? "{$observations} observations of this service updated."
            : null;
    }

    public function getSubheading(): ?string
    {
        return 'Services a postage source has reported. Mapping one gives it a name we already use; leaving it unmapped is a valid end state. Approving a mapped one lets automated shipping buy it for a client — until then it is a choice a packer makes by hand.';
    }
}

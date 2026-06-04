<?php

namespace App\Filament\Pages\Reports;

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Shipment;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ClientBillingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Client Billing';

    protected static UnitEnum|string|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 35;

    protected string $view = 'filament.pages.reports.client-billing-report';

    public string $viewMode = 'summary';

    public ?int $clientId = null;

    public static function canAccess(): bool
    {
        if (! (auth()->user()?->role->isAtLeast(Role::Manager) ?? false)) {
            return false;
        }

        return app(SettingsService::class)->get('multi_client_enabled', false);
    }

    public function updatedViewMode(): void
    {
        if ($this->viewMode === 'detail' && ! $this->clientId) {
            $this->clientId = Client::orderBy('name')->first()?->id;
        }

        $this->resetTable();
    }

    public function updatedClientId(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        if ($this->viewMode === 'detail' && $this->clientId) {
            return $this->detailTable($table);
        }

        return $this->summaryTable($table);
    }

    public function resolveTableRecord(?string $key): ?Model
    {
        if ($this->viewMode === 'detail') {
            return Shipment::find($key);
        }

        return Client::find($key);
    }

    public function getClientOptions(): array
    {
        return Client::orderBy('name')->pluck('name', 'id')->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $filters = $this->getTableFilterState('date_range') ?? [];
        $from = $filters['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $until = $filters['until'] ?? now()->endOfMonth()->format('Y-m-d');

        if ($this->viewMode === 'detail' && $this->clientId) {
            return $this->streamDetailCsv($this->clientId, $from, $until);
        }

        return $this->streamSummaryCsv($from, $until);
    }

    private function summaryTable(Table $table): Table
    {
        return $table
            ->query($this->summaryQuery())
            ->defaultSort('client_name')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('client_name')
                    ->label('Client')
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_count')
                    ->label('Orders')
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Packages')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_postage')
                    ->label('Postage')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('label_fees')
                    ->label('Label Fees')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pick_fees')
                    ->label('Pick Fees')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_materials')
                    ->label('Materials')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('product_surcharges')
                    ->label('Surcharges')
                    ->money('USD')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_billable')
                    ->label('Total Billable')
                    ->money('USD')
                    ->sortable()
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->live(onBlur: true)
                            ->default(now()->subMonth()->startOfMonth()->format('Y-m-d')),
                        DatePicker::make('until')
                            ->live(onBlur: true)
                            ->default(now()->subMonth()->endOfMonth()->format('Y-m-d')),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->where('billing.shipped_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->where('billing.shipped_at', '<=', $date.' 23:59:59'))
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function detailTable(Table $table): Table
    {
        $client = Client::find($this->clientId);

        $query = $this->detailQuery($this->clientId, $client);

        return $table
            ->query($query)
            ->defaultSort('pkg.shipped_at', 'desc')
            ->defaultKeySort(false)
            ->columns([
                Tables\Columns\TextColumn::make('shipment_reference')
                    ->label('Reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('shipped_at')
                    ->label('Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('package_count')
                    ->label('Pkgs'),
                Tables\Columns\TextColumn::make('postage')
                    ->label('Postage')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('label_fee')
                    ->label('Label Fee')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('pick_fee_base')
                    ->label('Pick Fee')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('pick_fee_extra')
                    ->label('Extra Items')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('materials')
                    ->label('Materials')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('product_surcharges')
                    ->label('Surcharges')
                    ->money('USD'),
                Tables\Columns\TextColumn::make('has_items')
                    ->label('')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state ? null : 'No item data')
                    ->placeholder(''),
                Tables\Columns\TextColumn::make('line_total')
                    ->label('Line Total')
                    ->money('USD')
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->live(onBlur: true)
                            ->default(now()->subMonth()->startOfMonth()->format('Y-m-d')),
                        DatePicker::make('until')
                            ->live(onBlur: true)
                            ->default(now()->subMonth()->endOfMonth()->format('Y-m-d')),
                    ])
                    ->columns(2)
                    ->default()
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->where('pkg.shipped_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->where('pkg.shipped_at', '<=', $date.' 23:59:59'))
                    ),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->filtersFormColumns(2);
    }

    private function summaryQuery(): Builder
    {
        $billing = $this->buildBillingBase();

        return Client::query()
            ->leftJoinSub($billing, 'billing', 'clients.id', '=', 'billing.client_id')
            ->select([
                'clients.id',
                DB::raw('clients.name as client_name'),
                DB::raw('COUNT(billing.shipment_id) as order_count'),
                DB::raw('COALESCE(SUM(billing.package_count), 0) as package_count'),
                DB::raw('COALESCE(SUM(billing.postage), 0) as total_postage'),
                DB::raw('COALESCE(SUM(billing.label_fee), 0) as label_fees'),
                DB::raw('COALESCE(SUM(billing.pick_fee_base + billing.pick_fee_extra), 0) as pick_fees'),
                DB::raw('COALESCE(SUM(billing.materials), 0) as total_materials'),
                DB::raw('COALESCE(SUM(billing.product_surcharges), 0) as product_surcharges'),
                DB::raw('COALESCE(SUM(billing.line_total), 0) as total_billable'),
            ])
            ->where('clients.active', true)
            ->groupBy('clients.id', 'clients.name');
    }

    private function detailQuery(int $clientId, ?Client $client): Builder
    {
        $firstItemFee = (float) ($client?->pick_fee_first_item ?? 0);
        $additionalItemFee = (float) ($client?->pick_fee_additional_item ?? 0);
        $labelFee = (float) ($client?->label_fee_per_package ?? 0);

        $pkgSub = DB::table('packages as p')
            ->leftJoin('box_sizes as bs', 'p.box_size_id', '=', 'bs.id')
            ->where('p.status', PackageStatus::Shipped->value)
            ->select([
                'p.shipment_id',
                DB::raw('MIN(p.shipped_at) as shipped_at'),
                DB::raw('COUNT(p.id) as package_count'),
                DB::raw('COALESCE(SUM(p.cost), 0) as postage'),
                DB::raw('COALESCE(SUM(p.weight), 0) as weight'),
                DB::raw('COALESCE(SUM(COALESCE(bs.materials_cost, 0)), 0) as materials'),
            ])
            ->groupBy('p.shipment_id');

        $itemSub = DB::table('shipment_items as si')
            ->leftJoin('products as pr', 'si.product_id', '=', 'pr.id')
            ->select([
                'si.shipment_id',
                DB::raw('SUM(si.quantity) as total_qty'),
                DB::raw('COALESCE(SUM(si.quantity * COALESCE(pr.handling_surcharge, 0)), 0) as product_surcharges'),
            ])
            ->groupBy('si.shipment_id');

        return Shipment::query()
            ->where('shipments.client_id', $clientId)
            ->joinSub($pkgSub, 'pkg', 'shipments.id', '=', 'pkg.shipment_id')
            ->leftJoinSub($itemSub, 'itm', 'shipments.id', '=', 'itm.shipment_id')
            ->select([
                'shipments.id',
                'shipments.shipment_reference',
                'pkg.shipped_at',
                'pkg.package_count',
                'pkg.postage',
                'pkg.weight',
                'pkg.materials',
                DB::raw('CASE WHEN itm.shipment_id IS NOT NULL THEN 1 ELSE 0 END as has_items'),
                DB::raw('COALESCE(itm.product_surcharges, 0) as product_surcharges'),
                DB::raw('pkg.package_count * '.$labelFee.' as label_fee'),
                DB::raw($firstItemFee.' as pick_fee_base'),
                DB::raw('GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * '.$additionalItemFee.' as pick_fee_extra'),
                DB::raw(
                    'pkg.postage + '.
                    'pkg.package_count * '.$labelFee.' + '.
                    $firstItemFee.' + '.
                    'GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * '.$additionalItemFee.' + '.
                    'pkg.materials + '.
                    'COALESCE(itm.product_surcharges, 0) as line_total'
                ),
            ]);
    }

    private function buildBillingBase(): \Illuminate\Database\Query\Builder
    {
        $pkgSub = DB::table('packages as p')
            ->leftJoin('box_sizes as bs', 'p.box_size_id', '=', 'bs.id')
            ->where('p.status', PackageStatus::Shipped->value)
            ->select([
                'p.shipment_id',
                DB::raw('MIN(p.shipped_at) as shipped_at'),
                DB::raw('COUNT(p.id) as package_count'),
                DB::raw('COALESCE(SUM(p.cost), 0) as postage'),
                DB::raw('COALESCE(SUM(p.weight), 0) as weight'),
                DB::raw('COALESCE(SUM(COALESCE(bs.materials_cost, 0)), 0) as materials'),
            ])
            ->groupBy('p.shipment_id');

        $itemSub = DB::table('shipment_items as si')
            ->leftJoin('products as pr', 'si.product_id', '=', 'pr.id')
            ->select([
                'si.shipment_id',
                DB::raw('SUM(si.quantity) as total_qty'),
                DB::raw('COALESCE(SUM(si.quantity * COALESCE(pr.handling_surcharge, 0)), 0) as product_surcharges'),
            ])
            ->groupBy('si.shipment_id');

        return DB::table('shipments as s')
            ->join('clients as c', 's.client_id', '=', 'c.id')
            ->joinSub($pkgSub, 'pkg', 's.id', '=', 'pkg.shipment_id')
            ->leftJoinSub($itemSub, 'itm', 's.id', '=', 'itm.shipment_id')
            ->select([
                's.id as shipment_id',
                's.client_id',
                'pkg.shipped_at',
                'pkg.package_count',
                'pkg.postage',
                'pkg.materials',
                DB::raw('COALESCE(itm.product_surcharges, 0) as product_surcharges'),
                DB::raw('pkg.package_count * COALESCE(c.label_fee_per_package, 0) as label_fee'),
                DB::raw('COALESCE(c.pick_fee_first_item, 0) as pick_fee_base'),
                DB::raw('GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * COALESCE(c.pick_fee_additional_item, 0) as pick_fee_extra'),
                DB::raw(
                    'pkg.postage + '.
                    'pkg.package_count * COALESCE(c.label_fee_per_package, 0) + '.
                    'COALESCE(c.pick_fee_first_item, 0) + '.
                    'GREATEST(0, COALESCE(itm.total_qty, 0) - 1) * COALESCE(c.pick_fee_additional_item, 0) + '.
                    'pkg.materials + '.
                    'COALESCE(itm.product_surcharges, 0) as line_total'
                ),
            ]);
    }

    private function streamSummaryCsv(string $from, string $until): StreamedResponse
    {
        $rows = $this->summaryQuery()
            ->when($from, fn ($q) => $q->where('billing.shipped_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('billing.shipped_at', '<=', $until.' 23:59:59'))
            ->get();

        $filename = 'client-billing-summary-'.$from.'-to-'.$until.'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Client', 'Orders', 'Packages', 'Postage', 'Label Fees', 'Pick Fees', 'Materials', 'Surcharges', 'Total Billable']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->client_name,
                    $row->order_count,
                    $row->package_count,
                    number_format((float) $row->total_postage, 2),
                    number_format((float) $row->label_fees, 2),
                    number_format((float) $row->pick_fees, 2),
                    number_format((float) $row->total_materials, 2),
                    number_format((float) $row->product_surcharges, 2),
                    number_format((float) $row->total_billable, 2),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function streamDetailCsv(int $clientId, string $from, string $until): StreamedResponse
    {
        $client = Client::find($clientId);

        $rows = $this->detailQuery($clientId, $client)
            ->when($from, fn ($q) => $q->where('pkg.shipped_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('pkg.shipped_at', '<=', $until.' 23:59:59'))
            ->orderBy('pkg.shipped_at')
            ->get();

        $filename = 'client-billing-'.$client?->code.'-'.$from.'-to-'.$until.'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Date', 'Packages', 'Postage', 'Label Fee', 'Pick Fee', 'Extra Items', 'Materials', 'Surcharges', 'No Item Data', 'Line Total']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->shipment_reference,
                    $row->shipped_at,
                    $row->package_count,
                    number_format((float) $row->postage, 2),
                    number_format((float) $row->label_fee, 2),
                    number_format((float) $row->pick_fee_base, 2),
                    number_format((float) $row->pick_fee_extra, 2),
                    number_format((float) $row->materials, 2),
                    number_format((float) $row->product_surcharges, 2),
                    $row->has_items ? '' : 'yes',
                    number_format((float) $row->line_total, 2),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

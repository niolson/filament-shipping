<?php

namespace App\Services\ShipmentImport;

use App\Models\DataSource;
use App\Models\DataSourceLocation;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DataSourceLocationResolver
{
    /**
     * @param  array{external_id?: string, external_code?: string|null, name?: string|null, address?: array<string, mixed>|null, is_active?: bool}  $reference
     */
    public function resolve(DataSource $dataSource, array $reference): DataSourceLocationResolution
    {
        $externalId = $reference['external_id'] ?? null;
        if (blank($externalId)) {
            throw new InvalidArgumentException('Source location is missing its external identifier.');
        }

        return $this->resolveBatch($dataSource, collect([$reference]))->get($externalId)
            ?? throw new InvalidArgumentException("Source location {$externalId} could not be resolved.");
    }

    /**
     * @param  Collection<int, covariant array<mixed, mixed>>  $references
     * @return Collection<string, DataSourceLocationResolution>
     */
    public function resolveBatch(DataSource $dataSource, Collection $references): Collection
    {
        $now = now();
        $referencesByExternalId = $references
            ->filter(fn (array $reference): bool => filled($reference['external_id'] ?? null))
            ->keyBy(fn (array $reference): string => (string) $reference['external_id']);

        if ($referencesByExternalId->isEmpty()) {
            return collect();
        }

        $rows = $referencesByExternalId->map(function (array $reference, string $externalId) use ($dataSource, $now): array {
            $address = $reference['address'] ?? null;

            return [
                'data_source_id' => $dataSource->id,
                'external_id' => $externalId,
                'external_code' => $reference['external_code'] ?? null,
                'name' => $reference['name'] ?? $externalId,
                'address' => is_array($address) ? json_encode($address) : $address,
                'is_active' => $reference['is_active'] ?? true,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values()->all();

        DataSourceLocation::upsert(
            $rows,
            ['data_source_id', 'external_id'],
            ['external_code', 'name', 'address', 'is_active', 'last_seen_at', 'updated_at'],
        );

        return $dataSource->locations()
            ->with('location')
            ->whereIn('external_id', $referencesByExternalId->keys())
            ->get()
            ->mapWithKeys(fn (DataSourceLocation $sourceLocation): array => [
                $sourceLocation->external_id => $this->resolutionFor($sourceLocation),
            ]);
    }

    private function resolutionFor(DataSourceLocation $sourceLocation): DataSourceLocationResolution
    {
        if ($sourceLocation->isIgnored()) {
            return new DataSourceLocationResolution($sourceLocation, ignored: true);
        }

        if (! $sourceLocation->is_active) {
            return new DataSourceLocationResolution(
                $sourceLocation,
                error: "Source location {$sourceLocation->name} is inactive.",
            );
        }

        if (! $sourceLocation->isMapped() || ! $sourceLocation->location?->active) {
            return new DataSourceLocationResolution(
                $sourceLocation,
                error: "Source location {$sourceLocation->name} is not mapped to an active PolyBag location.",
            );
        }

        return new DataSourceLocationResolution($sourceLocation, $sourceLocation->location);
    }
}

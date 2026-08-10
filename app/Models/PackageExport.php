<?php

namespace App\Models;

use App\Enums\PackageExportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property PackageExportStatus $status */
class PackageExport extends Model
{
    protected $fillable = [
        'package_id',
        'data_source_id',
        'status',
        'attempts',
        'last_error',
        'locked_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PackageExportStatus::class,
            'attempts' => 'integer',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}

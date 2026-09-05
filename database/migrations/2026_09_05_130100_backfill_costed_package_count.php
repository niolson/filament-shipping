<?php

use App\Enums\PackageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The rollup is a pure function of `packages`, but the nightly schedule
        // only rebuilds yesterday and today, so history would never acquire a
        // costed count on its own — `stats:aggregate` has to be asked for a
        // range explicitly. Recompute it here instead of leaving every past row
        // null, since the reports read months back.
        //
        // Five of the six grouping keys are nullable, and NULL = NULL is
        // unknown in both engines, so each nullable key is matched by equality
        // OR both sides being null. A COALESCE sentinel would be shorter and
        // wrong: `service` is genuinely empty on some shipped packages as well
        // as genuinely null, GROUP BY keeps those apart, and collapsing them
        // here would hand each of the two rollup rows the other's packages —
        // enough to push `costed_package_count` past `package_count`.
        //
        // COUNT(p.cost) counts non-null costs, which is the whole measurement.
        // The CASE distinguishes "every package here was unpriced" (0) from
        // "no packages match this row at all" (null) — a stale rollup row left
        // by a deleted or re-dated package should keep reading as uncomputed,
        // not as fully unpriced.
        DB::statement('
            UPDATE daily_shipping_stats AS d
            SET costed_package_count = (
                SELECT CASE WHEN COUNT(p.id) = 0 THEN NULL ELSE COUNT(p.cost) END
                FROM packages p
                JOIN shipments s ON p.shipment_id = s.id
                WHERE p.status = ?
                  AND p.ship_date = d.date
                  AND (p.carrier = d.carrier OR (p.carrier IS NULL AND d.carrier IS NULL))
                  AND (p.service = d.service OR (p.service IS NULL AND d.service IS NULL))
                  AND (s.channel_id = d.channel_id OR (s.channel_id IS NULL AND d.channel_id IS NULL))
                  AND (s.shipping_method_id = d.shipping_method_id OR (s.shipping_method_id IS NULL AND d.shipping_method_id IS NULL))
                  AND (p.location_id = d.location_id OR (p.location_id IS NULL AND d.location_id IS NULL))
            )
        ', [PackageStatus::Shipped->value]);
    }

    public function down(): void
    {
        DB::table('daily_shipping_stats')->update(['costed_package_count' => null]);
    }
};

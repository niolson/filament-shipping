<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Heal scopes left pointing at a carrier their account no longer belongs to.
 *
 * `carrier_account_scopes.carrier_id` is denormalized so the unique index can
 * enforce one account per (carrier, location, client), and it was derived only
 * in the scope's own `saving` hook — which never fired when the *account* moved
 * carriers. A drifted row resolves the account for the wrong carrier and hides
 * it from the right one, and because the index keys on the stale value it also
 * lets a second, legitimate row occupy the same slot.
 *
 * `CarrierAccount::restampScopes()` now keeps these in step. This is the
 * one-time repair for rows that drifted before it existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $drifted = DB::table('carrier_account_scopes as scopes')
                ->join('carrier_accounts as accounts', 'accounts.id', '=', 'scopes.carrier_account_id')
                ->whereColumn('scopes.carrier_id', '!=', 'accounts.carrier_id')
                ->orderBy('scopes.id')
                ->get(['scopes.id', 'scopes.location_id', 'scopes.client_id', 'accounts.carrier_id as target_carrier_id']);

            foreach ($drifted as $scope) {
                // The slot on the target carrier may already be held, by another
                // account or by an earlier row in this same loop. The index would
                // reject the move, and a row naming a carrier its account left
                // can only resolve wrongly, so it goes.
                $taken = DB::table('carrier_account_scopes')
                    ->where('carrier_id', $scope->target_carrier_id)
                    ->where(fn ($query) => $scope->location_id === null
                        ? $query->whereNull('location_id')
                        : $query->where('location_id', $scope->location_id))
                    ->where(fn ($query) => $scope->client_id === null
                        ? $query->whereNull('client_id')
                        : $query->where('client_id', $scope->client_id))
                    ->where('id', '!=', $scope->id)
                    ->exists();

                if ($taken) {
                    logger()->warning('Dropped a drifted carrier account scope whose slot was already held', [
                        'carrier_account_scope_id' => $scope->id,
                        'target_carrier_id' => $scope->target_carrier_id,
                    ]);

                    DB::table('carrier_account_scopes')->where('id', $scope->id)->delete();

                    continue;
                }

                DB::table('carrier_account_scopes')
                    ->where('id', $scope->id)
                    ->update(['carrier_id' => $scope->target_carrier_id]);
            }
        });
    }

    public function down(): void
    {
        // The old carrier_id was wrong by construction; there is nothing to restore it to.
    }
};

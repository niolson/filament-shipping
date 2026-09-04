<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record how well a package's service is known, and what was asked for,
     * beside the service value itself. See ADR-0003 decision 7.
     *
     * Unlike `postage_source`, the evidence column lands NOT NULL with a default
     * rather than nullable: `unknown` is not a claim about a row nobody has
     * looked at, it is the honest reading of one, and every service value has to
     * carry evidence for the column to mean anything. The backfill that follows
     * only ever upgrades a row to `confirmed`.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // What we asked the postage source for — audit metadata, never the
            // service value. A source may ignore it outright, which is exactly
            // why it is worth keeping beside what came back.
            $table->string('requested_service')
                ->nullable()
                ->after('service');

            $table->string('service_evidence', 16)
                ->default('unknown')
                ->after('requested_service');

            // Both only ever set together, and only when the evidence is
            // `inferred`: an inference nobody can reproduce is not evidence.
            $table->string('service_inference_method', 64)
                ->nullable()
                ->after('service_evidence');

            $table->string('service_ruleset_version', 32)
                ->nullable()
                ->after('service_inference_method');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'requested_service',
                'service_evidence',
                'service_inference_method',
                'service_ruleset_version',
            ]);
        });
    }
};

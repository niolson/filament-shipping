<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isMySQL = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMySQL) {
            // MySQL requires dropping the SET NULL FK before the column can become
            // NOT NULL. Some installs are missing the FK (schema drift), so only
            // drop it where it actually exists — the migration recreates it below.
            $this->dropClientIdForeignIfExists('shipping_method_aliases');
            $this->dropClientIdForeignIfExists('channel_aliases');
        }

        // Assign any null rows to the default client before tightening the column.
        // Installs that were never seeded may have orphaned rows but no default
        // client — create one so the backfill has a target.
        $hasOrphans = DB::table('shipping_method_aliases')->whereNull('client_id')->exists()
            || DB::table('channel_aliases')->whereNull('client_id')->exists();

        if ($hasOrphans) {
            $this->ensureDefaultClientExists();
        }

        DB::statement('UPDATE shipping_method_aliases SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');
        DB::statement('UPDATE channel_aliases SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');

        Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());
        Schema::table('channel_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());

        if ($isMySQL) {
            Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
            Schema::table('channel_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        $isMySQL = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMySQL) {
            $this->dropClientIdForeignIfExists('shipping_method_aliases');
            $this->dropClientIdForeignIfExists('channel_aliases');
        }

        Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());
        Schema::table('channel_aliases', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());

        if ($isMySQL) {
            Schema::table('shipping_method_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
            Schema::table('channel_aliases', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
        }
    }

    private function ensureDefaultClientExists(): void
    {
        if (DB::table('clients')->where('is_default', true)->exists()) {
            return;
        }

        $client = [
            'name' => 'Default Client',
            'is_default' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Dropped by a later migration, but still present at this point in history
        if (Schema::hasColumn('clients', 'code')) {
            $client['code'] = 'DEFAULT';
        }

        DB::table('clients')->insert($client);
    }

    private function dropClientIdForeignIfExists(string $table): void
    {
        $hasClientIdForeign = collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['client_id']);

        if ($hasClientIdForeign) {
            Schema::table($table, fn (Blueprint $table) => $table->dropForeign(['client_id']));
        }
    }
};

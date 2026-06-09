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
            // Some installs are missing the FK (schema drift) — drop only when present
            $this->dropClientIdForeignIfExists('products');
            $this->dropClientIdForeignIfExists('shipments');
        }

        // Installs that were never seeded may have orphaned rows but no default
        // client — create one so the backfill has a target.
        $hasOrphans = DB::table('products')->whereNull('client_id')->exists()
            || DB::table('shipments')->whereNull('client_id')->exists();

        if ($hasOrphans) {
            $this->ensureDefaultClientExists();
        }

        DB::statement('UPDATE products SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');
        DB::statement('UPDATE shipments SET client_id = (SELECT id FROM clients WHERE is_default = 1 LIMIT 1) WHERE client_id IS NULL');

        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());
        Schema::table('shipments', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable(false)->change());

        if ($isMySQL) {
            Schema::table('products', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
            Schema::table('shipments', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        $isMySQL = Schema::getConnection()->getDriverName() === 'mysql';

        if ($isMySQL) {
            $this->dropClientIdForeignIfExists('products');
            $this->dropClientIdForeignIfExists('shipments');
        }

        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());
        Schema::table('shipments', fn (Blueprint $table) => $table->unsignedBigInteger('client_id')->nullable()->change());

        if ($isMySQL) {
            Schema::table('products', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
            Schema::table('shipments', fn (Blueprint $table) => $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete());
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

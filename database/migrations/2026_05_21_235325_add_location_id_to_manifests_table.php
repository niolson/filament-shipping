<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete()->after('carrier');
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropForeignIdFor(Location::class);
            $table->dropColumn('location_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Squashed migration: no-op on installs that ran the pre-squash history.
        if (Schema::hasTable('shipments')) {
            return;
        }

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipment_reference')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city');
            $table->string('state_or_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country');
            $table->boolean('residential')->default(true);
            $table->string('phone')->nullable();
            $table->string('phone_e164')->nullable();
            $table->string('phone_extension', 6)->nullable();
            $table->string('email')->nullable();
            $table->decimal('value', 8, 2)->nullable();
            $table->boolean('checked')->default(false);
            $table->string('deliverability')->default('not_checked');
            $table->text('validation_message')->nullable();
            $table->string('validated_company')->nullable();
            $table->string('validated_address1')->nullable();
            $table->string('validated_address2')->nullable();
            $table->string('validated_city')->nullable();
            $table->string('validated_state_or_province')->nullable();
            $table->string('validated_postal_code')->nullable();
            $table->string('validated_country')->nullable();
            $table->boolean('validated_residential')->nullable();
            $table->string('validated_carrier_route')->nullable();
            $table->string('shipping_method_reference')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained();
            $table->foreignId('channel_id')->nullable()->constrained();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->foreignId('data_source_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('picking_status', 16)->default('pending');
            $table->date('deliver_by')->nullable();
            $table->timestamps();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->date('updated_date')->nullable()->storedAs('DATE(updated_at)');
            }

            $table->unique(['data_source_id', 'source_record_id'], 'shipments_data_source_record_unique');
            $table->index('shipment_reference');
            $table->index('created_at');
            $table->index('deliverability');
            $table->index('channel_id', 'shipments_channel_id_index');
            $table->index('channel_reference');
            $table->index('shipping_method_reference');
            $table->index('status');
            $table->index('picking_status');
            $table->index('client_id');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->index(['status', 'updated_date'], 'shipments_status_updated_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};

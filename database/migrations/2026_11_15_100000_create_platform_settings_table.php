<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The installation's money rules and switches, one row (App\Models\PlatformSettings::current()).
     * Columns, not a key/value or JSON blob, so each value is typed and its changes are audited.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Commission on each shop order's items, in basis points: 300 = 3% (D23). Integers, never floats.
            $table->unsignedInteger('commission_bps')->default(300);
            // Fixed fee and service fee per shop order, in UGX (D4, D23). 0 at launch.
            $table->unsignedInteger('fixed_fee_per_order')->default(0);
            $table->unsignedInteger('service_fee_per_order')->default(0);
            // When an unpaid balance hides a shop (D28): over this amount, or anything unpaid this many days.
            $table->unsignedInteger('debt_limit_amount')->default(50000);
            $table->unsignedSmallInteger('debt_limit_days')->default(14);

            // Which ways of paying the platform offers at all (D15, D34); each shop then chooses its own.
            $table->boolean('marzpay_enabled')->default(true);
            $table->boolean('olycash_enabled')->default(true);
            $table->boolean('card_enabled')->default(false);
            $table->boolean('pay_on_delivery_enabled')->default(true);

            $table->timestamps();
            $table->ulid('updated_by')->nullable();

            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};

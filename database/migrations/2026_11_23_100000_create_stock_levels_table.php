<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many of a variant are at one location now, and how many of those are held for orders being
     * paid or not yet sent (D5, D33). Available = on hand - held, worked out, never stored. Changed only
     * by App\Services\StockLedger, each change a stock movement, so the figure can always be explained.
     * Decimal: weighed and measured units sell in parts (1.5 kg). docs/stock.md.
     */
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_variant_id');
            $table->ulid('location_id');
            $table->decimal('on_hand', 12, 3)->default(0);
            $table->decimal('reserved', 12, 3)->default(0);   // held for orders (D33)
            $table->timestamps();

            $table->unique(['product_variant_id', 'location_id']);
            $table->index('location_id');

            $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('restrict');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the buyer picks and pays for: a Red 20 L basin. Its name comes from its option values (never
     * stored). Price in whole shillings, sold by one unit, with quantity price breaks (D17). Stock is per
     * variant per location (step 3).
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('sku', 60)->nullable();                 // the shop's own code; unique within the shop
            $table->string('barcode', 60)->nullable();             // as printed: EAN-13, UPC
            $table->ulid('unit_id');
            $table->unsignedInteger('price');                      // per unit
            $table->unsignedInteger('compare_at_price')->nullable(); // the was-price, above price
            $table->unsignedInteger('weight_grams')->nullable();   // per unit, for delivery by weight
            $table->unsignedSmallInteger('position')->default(0);
            // Off: not offered for now, without losing its history.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'position']);
            $table->index('sku');
            $table->index('barcode');

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('unit_id')->references('id')->on('units')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};

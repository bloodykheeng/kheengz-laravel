<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wholesale (D17): from this quantity, each costs less. 12 or more: UGX 4,500 each.
     */
    public function up(): void
    {
        Schema::create('price_breaks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_variant_id');
            $table->decimal('min_quantity', 10, 3);                // in the variant's unit; whole unless it takes decimals
            $table->unsignedInteger('unit_price');                 // below the variant's price
            $table->timestamps();

            $table->unique(['product_variant_id', 'min_quantity']);

            $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_breaks');
    }
};

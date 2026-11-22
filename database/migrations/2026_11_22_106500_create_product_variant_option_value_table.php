<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A variant's value for each of its product's options: Red and 20 L.
     */
    public function up(): void
    {
        Schema::create('product_variant_option_value', function (Blueprint $table) {
            $table->ulid('product_variant_id');
            $table->ulid('product_option_value_id');

            $table->primary(['product_variant_id', 'product_option_value_id']);
            $table->index('product_option_value_id');

            $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('restrict');
            $table->foreign('product_option_value_id')->references('id')->on('product_option_values')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_option_value');
    }
};

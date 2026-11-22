<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The values of a product option (Size: 10 L, 20 L).
     */
    public function up(): void
    {
        Schema::create('product_option_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_option_id');
            $table->string('value', 60);
            $table->ulid('product_attribute_option_id')->nullable(); // the filter choice it counts as (Red)
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_option_id', 'value']);

            $table->foreign('product_option_id')->references('id')->on('product_options')->onDelete('restrict');
            $table->foreign('product_attribute_option_id')->references('id')->on('product_attribute_options')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};

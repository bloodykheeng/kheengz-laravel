<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What describes products for filters and comparison (D32): Colour, Capacity, Gauge, Variety. Defined
     * once by the Admin and given to categories; a category's products are described by its own and its
     * parents' attributes. docs/catalogue.md.
     */
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 60)->unique();                  // Colour, Capacity, Gauge
            $table->string('input_type', 20);                      // AttributeInputType: List, Number, Text
            $table->string('unit', 20)->nullable();                // for Number: L, cm, kg, gauge
            $table->string('hint', 160)->nullable();               // shown under the field on the product form

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};

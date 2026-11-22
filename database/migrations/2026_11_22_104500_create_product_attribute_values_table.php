<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A product's value for one of its category's attributes: a choice, a number, or text, by the attribute's
     * input type. Rows, so filters and reports count them (no JSON).
     */
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->ulid('product_attribute_id');
            $table->ulid('product_attribute_option_id')->nullable(); // List
            $table->decimal('value_number', 12, 3)->nullable();     // Number, in the attribute's unit
            $table->string('value_text', 120)->nullable();          // Text
            $table->timestamps();

            $table->unique(['product_id', 'product_attribute_id']);
            $table->index(['product_attribute_id', 'product_attribute_option_id'], 'pav_attribute_option_index');
            $table->index(['product_attribute_id', 'value_number'], 'pav_attribute_number_index');

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_attribute_id')->references('id')->on('product_attributes')->onDelete('restrict');
            $table->foreign('product_attribute_option_id')->references('id')->on('product_attribute_options')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};

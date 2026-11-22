<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which attributes a category's products are described by, in order. Children inherit their parents':
     * Basins gets Household's Colour as well as its own Capacity.
     */
    public function up(): void
    {
        Schema::create('category_product_attribute', function (Blueprint $table) {
            $table->ulid('category_id');
            $table->ulid('product_attribute_id');
            $table->unsignedSmallInteger('position')->default(0);
            // Needed before a product in this category can go on sale.
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->primary(['category_id', 'product_attribute_id']);

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('product_attribute_id')->references('id')->on('product_attributes')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product_attribute');
    }
};

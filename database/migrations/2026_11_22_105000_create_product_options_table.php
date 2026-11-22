<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An option the vendor sets on their own product (Size, Colour, Scent), never limited to the category's
     * attributes (D32). When it matches one (Colour), its values also feed that filter.
     */
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('name', 40);
            $table->ulid('product_attribute_id')->nullable();      // the attribute it feeds, when it matches one
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'name']);

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_attribute_id')->references('id')->on('product_attributes')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The choices of a List attribute (Colour: Red, Blue). Rows, so filters can count products per choice.
     */
    public function up(): void
    {
        Schema::create('product_attribute_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_attribute_id');
            $table->string('value', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_attribute_id', 'value']);

            $table->foreign('product_attribute_id')->references('id')->on('product_attributes')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_options');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of its shop's sections a product is in (none, one or several), and its place there.
     */
    public function up(): void
    {
        Schema::create('product_shop_section', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('shop_section_id');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->primary(['product_id', 'shop_section_id']);
            $table->index(['shop_section_id', 'position']);

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('shop_section_id')->references('id')->on('shop_sections')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_shop_section');
    }
};

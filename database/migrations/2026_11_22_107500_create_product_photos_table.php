<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A product's photos, in order; the first is its cover. Re-encoded to WebP at two sizes with the camera's
     * metadata (GPS) stripped, on the public disk. One may show a particular variant.
     */
    public function up(): void
    {
        Schema::create('product_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->ulid('product_variant_id')->nullable();
            $table->string('path');                                // up to 1600 px, public disk
            $table->string('thumb_path');                          // up to 480 px
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('source_url', 2048)->nullable();        // when copied from a pasted link
            $table->timestamps();
            $table->ulid('created_by')->nullable();

            $table->index(['product_id', 'position']);

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photos');
    }
};

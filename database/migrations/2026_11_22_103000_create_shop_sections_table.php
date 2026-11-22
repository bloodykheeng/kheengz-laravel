<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop's own groups, named as the vendor likes (D32): "Back to school", "Wedding sets", "Under 10k". A
     * product is in none, one or several; they make the shop's menu and homepage sections.
     */
    public function up(): void
    {
        Schema::create('shop_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shop_id');
            $table->string('name', 60);
            $table->string('slug', 80);                            // homenest.kheengz.com/s/back-to-school
            $table->string('photo_path')->nullable();              // public disk, WebP
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->unique(['shop_id', 'slug']);
            $table->unique(['shop_id', 'name']);

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('restrict');

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_sections');
    }
};

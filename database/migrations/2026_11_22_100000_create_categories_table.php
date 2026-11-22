<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The marketplace's own category tree, kept by the Admin (D32): Household > Kitchen > Basins. Every
     * product sits in one, the most specific that fits; buyers browse and filter by it across shops.
     * A shop's own groups are shop sections, not this. docs/catalogue.md.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->string('name', 80);
            // For marketplace links: /c/basins. Unique across the tree, so a link never needs the path.
            $table->string('slug', 100)->unique();
            $table->string('icon', 40)->nullable();                // a PrimeIcons name: pi-box
            $table->unsignedSmallInteger('position')->default(0);  // order among its siblings
            // Off: vendors can no longer choose it; products already in it keep it until moved.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->unique(['parent_id', 'name']);
            $table->index(['parent_id', 'position']);

            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('restrict');

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

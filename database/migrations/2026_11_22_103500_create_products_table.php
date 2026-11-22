<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A product of one shop, in one marketplace category (D32). What the buyer picks and pays for is one of its
     * variants; a product with no options has exactly one. docs/catalogue.md.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shop_id');
            $table->ulid('category_id');
            $table->string('name', 150)->index();
            $table->string('slug', 170);                           // homenest.kheengz.com/p/20-litre-basin
            $table->text('description')->nullable();
            $table->string('status', 20)->default('Draft');        // ProductStatus
            // Collected at the shop only (too big or fragile for a boda); otherwise delivered or collected.
            $table->boolean('collect_only')->default(false);
            $table->timestamp('published_at')->nullable();         // first put on sale
            // Taken down by Kheengz (D35): Hidden, and the vendor cannot put it back until the Admin allows it.
            $table->string('blocked_reason', 500)->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->ulid('blocked_by')->nullable();

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->unique(['shop_id', 'slug']);
            $table->index(['shop_id', 'status']);
            $table->index(['category_id', 'status']);

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('restrict');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('blocked_by')->references('id')->on('users')->onDelete('restrict');

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

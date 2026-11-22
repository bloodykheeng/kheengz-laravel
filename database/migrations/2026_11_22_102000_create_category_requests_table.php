<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A vendor asks for a category the tree lacks, from the product form (D32). The Admin adds it (or points
     * to one that exists) or declines with a reason, and the vendor is told. Until then the product sits in
     * the nearest category.
     */
    public function up(): void
    {
        Schema::create('category_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shop_id');
            $table->string('name', 80);                            // what they asked for
            $table->ulid('parent_id')->nullable();                 // where they think it belongs
            $table->string('note', 500)->nullable();               // what they sell that needs it
            $table->string('status', 20)->default('Pending')->index(); // CategoryRequestStatus
            $table->ulid('category_id')->nullable();               // the category that answers it
            $table->string('answer', 500)->nullable();             // the Admin's words to the vendor
            $table->timestamp('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->index(['shop_id', 'status']);

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('restrict');
            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('decided_by')->references('id')->on('users')->onDelete('restrict');

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_requests');
    }
};

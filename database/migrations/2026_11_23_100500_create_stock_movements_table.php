<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every change to a stock level and why (D33): received, counted, damaged, returned, moved between
     * locations, held for an order, released, sold. Never edited or deleted: a mistake is put right by
     * another movement. The figures after each movement are kept, as a stock card shows them, so a
     * recount can be checked line by line even after later changes. docs/stock.md.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_variant_id');
            $table->ulid('location_id');
            $table->string('reason', 30);                        // StockMovementReason
            $table->decimal('on_hand_change', 12, 3)->default(0); // + in, - out
            $table->decimal('reserved_change', 12, 3)->default(0);
            $table->decimal('on_hand_after', 12, 3);
            $table->decimal('reserved_after', 12, 3);
            $table->string('note', 255)->nullable();
            // What it came from: the order or counter sale (steps 5 and 7), the other side of a move.
            $table->string('source_type', 40)->nullable();
            $table->ulid('source_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->ulid('created_by')->nullable();              // null: the system (an order, a timeout)

            $table->index(['product_variant_id', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['source_type', 'source_id']);

            $table->foreign('product_variant_id')->references('id')->on('product_variants')->onDelete('restrict');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

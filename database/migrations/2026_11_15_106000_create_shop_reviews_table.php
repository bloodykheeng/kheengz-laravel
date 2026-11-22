<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop's history: each time it was sent for review, approved, sent back, rejected, suspended,
     * reinstated or closed, with the reason and who. Written once, never edited.
     */
    public function up(): void
    {
        Schema::create('shop_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shop_id');
            $table->string('decision', 30)->comment('Submitted, Approved, Changes requested, Rejected, Suspended, Reinstated, Closed (App\\Enums\\ShopReviewDecision)');
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->ulid('created_by')->nullable();

            $table->index(['shop_id', 'created_at']);

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_reviews');
    }
};

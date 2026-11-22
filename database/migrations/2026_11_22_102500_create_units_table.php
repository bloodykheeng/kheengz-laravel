<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How things are sold: piece, dozen, bale, kg, metre, bag, bunch (D17). The Admin keeps the list; each
     * shop type suggests its own first (ShopType::suggestedUnits). Weighed and measured units take decimals.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 30)->unique();                  // piece, kg
            $table->string('plural', 30);                          // pieces, kg
            $table->boolean('allows_decimals')->default(false);    // 1.5 kg yes, 1.5 pieces no
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};

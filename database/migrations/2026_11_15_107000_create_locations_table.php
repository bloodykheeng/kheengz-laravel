<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a shop keeps stock and sends orders from: the shop itself, a store room (D5). Every shop
     * gets one when it is made; stock levels (step 3) are per variant per location.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shop_id');
            $table->string('name');
            $table->string('address');
            $table->string('area')->index();                       // Ntinda, Kikuubo, Wandegeya
            $table->string('phone', 20)->nullable();
            $table->string('opening_hours')->nullable();           // as the shop writes it: "Mon to Sat, 8am to 7pm"
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->index(['shop_id', 'is_default']);

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};

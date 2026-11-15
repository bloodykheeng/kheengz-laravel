<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which shops a vendor's Staff member may run (D31). Nothing but the pair, so singular and
     * alphabetical, written by sync() (docs/migrations-and-table-naming.md §3). Owners are not listed:
     * they run every shop of their vendor.
     */
    public function up(): void
    {
        Schema::create('shop_user', function (Blueprint $table) {
            $table->ulid('shop_id');
            $table->ulid('user_id');
            $table->timestamps();

            $table->primary(['shop_id', 'user_id']);
            $table->index('user_id');

            $table->foreign('shop_id')->references('id')->on('shops')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_user');
    }
};

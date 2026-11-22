<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who works for a vendor, as Owner or Staff (D26). Plural because it carries a role; the pair
     * is still the primary key (docs/migrations-and-table-naming.md §3).
     */
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table) {
            $table->ulid('vendor_id');
            $table->ulid('user_id');
            $table->string('role', 20)->comment('Owner, Staff (App\\Enums\\VendorMemberRole)');
            $table->timestamps();
            $table->ulid('created_by')->nullable();

            $table->primary(['vendor_id', 'user_id']);
            $table->index('user_id');

            // Restrict both ways: removing a member deletes this row through the model, never a
            // vendor or an account.
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_users');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The person or business we verify once (D35) and who runs one or more shops (D31). Shops,
     * members, documents and payout destinations hang off it. docs/vendors-and-shops.md.
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The name the vendor trades and is paid under; checked against their ID or registration.
            $table->string('name')->index();
            $table->string('kind', 20)->comment('Individual, Company (App\\Enums\\VendorKind)');
            // The owner's National Identification Number (NIN), always: a company is run by someone.
            $table->string('national_id_number', 14)->unique();
            $table->string('registration_number', 40)->nullable(); // URSB number, companies only
            $table->string('phone', 20);                           // digits with 256
            $table->string('email')->nullable();
            $table->string('address')->nullable();

            $table->string('status', 20)->default('Pending')->index()->comment('Pending, Approved, Rejected, Suspended (App\\Enums\\VendorStatus)');
            // Why the Admin rejected or suspended it, shown to the vendor.
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            // Restrict throughout: a vendor's history (orders, payouts) outlives nothing silently.
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};

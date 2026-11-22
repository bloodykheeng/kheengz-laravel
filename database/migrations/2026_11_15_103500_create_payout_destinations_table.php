<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a vendor's money goes (D12): a Mobile Money number or a bank account. Never edited: a
     * change is a new destination, and each payout (step 6) copies the details it used, so history
     * never moves. Each shop picks one (shops.payout_destination_id). docs/vendors-and-shops.md.
     */
    public function up(): void
    {
        Schema::create('payout_destinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('vendor_id');

            $table->string('method', 20)->comment('Mobile Money, Bank (App\\Enums\\PayoutMethod)');
            $table->string('network', 10)->nullable()->comment('MTN, Airtel (App\\Enums\\MobileNetwork)');
            $table->string('bank_code', 20)->nullable();           // MarzPay's code (EQU), Bank only
            $table->string('bank_name', 120)->nullable();          // as MarzPay names it, Bank only
            // The Mobile Money number (digits with 256) or the bank account number.
            $table->string('account_number', 40);
            // The name the vendor says is on the account, and the name the bank returned (Bank only).
            $table->string('account_name', 160);
            $table->string('checked_name', 160)->nullable();

            $table->string('status', 20)->index()->comment('Verified, Awaiting review, Rejected (App\\Enums\\PayoutDestinationStatus)');
            // Why it waits or was refused, shown to the vendor and the Admin.
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->ulid('reviewed_by')->nullable();

            $table->timestamps();
            $table->ulid('created_by')->nullable();

            $table->index(['vendor_id', 'status']);

            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_destinations');
    }
};

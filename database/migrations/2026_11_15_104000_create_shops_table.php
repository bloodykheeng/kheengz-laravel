<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop: what buyers see and buy from. A vendor can run several, each approved on its own (D31).
     * Its look (logo, colours, homepage sections) arrives with step 4. docs/vendors-and-shops.md.
     */
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('vendor_id');

            $table->string('name')->index();
            // The shop's address name: `homenest` → homenest.kheengz.com (D26). Never reused while it exists.
            $table->string('slug', 40)->unique();
            $table->string('shop_type', 40)->comment('Household & home, Electronics & phones, Groceries & fresh produce, Fashion & beauty, Hardware & building, General (App\\Enums\\ShopType)');
            $table->string('description', 1000)->nullable();
            $table->string('phone', 20);                           // digits with 256; on the shop page
            $table->string('email')->nullable();

            $table->string('status', 30)->default('Draft')->index()->comment('Draft, Pending review, Changes requested, Active, Rejected, Suspended, Closed (App\\Enums\\ShopStatus)');
            $table->string('status_reason', 500)->nullable();      // the Admin's latest reason, shown to the vendor
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();

            // Where this shop's money is paid (D12): one of its vendor's payout destinations. Needed to
            // send the shop for review, and Verified to approve it.
            $table->ulid('payout_destination_id')->nullable();

            // This shop's own terms when they differ from the platform's (D23, D28); null = the platform's.
            $table->unsignedInteger('commission_bps')->nullable();
            $table->unsignedInteger('debt_limit_amount')->nullable();
            $table->unsignedSmallInteger('debt_limit_days')->nullable();

            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->index(['vendor_id', 'status']);

            // Restrict: a shop with orders and payouts is closed, never deleted.
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('payout_destination_id')->references('id')->on('payout_destinations')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};

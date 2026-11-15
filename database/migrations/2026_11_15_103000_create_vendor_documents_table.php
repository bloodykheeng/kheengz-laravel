<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The papers a vendor gives for approval: ID card sides, business registration. Files live on the
     * private disk and are served only to the vendor's Owner and to people who review vendors.
     */
    public function up(): void
    {
        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('vendor_id');
            $table->string('type', 40);                            // VendorDocumentType
            $table->string('file_path');                           // on the private ("local") disk
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->timestamp('checked_at')->nullable();           // the Admin looked at it
            $table->ulid('checked_by')->nullable();

            $table->timestamps();
            $table->ulid('created_by')->nullable();

            $table->index(['vendor_id', 'type']);

            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('checked_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
    }
};

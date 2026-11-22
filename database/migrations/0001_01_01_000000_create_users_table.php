<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->index();

            // Any one of these signs a person in. Each is nullable and unique
            // (MySQL permits many nulls in a unique index); that at least one
            // is present is the API's rule, not the schema's.
            $table->string('username')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password')->nullable();

            $table->boolean('allow_notifications')->default(true);
            $table->string('status')->default('Active')->index()->comment('Active, Inactive (App\\Enums\\RecordStatus)');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('lastlogin')->nullable();
            // When a vendor who signed themselves up agreed to the Terms and the Privacy Policy.
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('photo_source', 20)->nullable()->comment('Upload, Link, External (App\\Enums\\PhotoSource)');
            $table->string('photo_source_url', 2048)->nullable();  // the link a Link photo was copied from
            $table->string('timezone')->nullable()->default('Africa/Kampala');
            $table->rememberToken();
            $table->timestamps();
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};

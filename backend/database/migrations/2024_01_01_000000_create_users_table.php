<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->boolean('is_admin')->default(false)->index();
            $table->string('phone_number')->nullable()->unique();
            $table->integer('daily_requests_count')->default(0);
            $table->date('daily_requests_date')->nullable()->index();
            $table->string('last_sms_fingerprint', 64)->nullable()->index();
            $table->text('last_sms_reply')->nullable();
            $table->timestamp('last_sms_received_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

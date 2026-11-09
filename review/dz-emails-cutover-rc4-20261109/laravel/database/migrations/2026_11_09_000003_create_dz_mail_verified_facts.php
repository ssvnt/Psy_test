<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('dz_mail_test_completions',function(Blueprint $table):void {
            $table->bigIncrements('id');
            $table->string('product',16);
            $table->char('session_key_hash',64);
            $table->timestampTz('completed_at');
            $table->timestampsTz();
            $table->unique(['product','session_key_hash'],'dz_mail_unique_completion');
        });
        Schema::create('dz_mail_verified_payments',function(Blueprint $table):void {
            $table->bigIncrements('id');
            $table->string('product',16);
            $table->char('session_key_hash',64);
            $table->char('payment_operation_hash',64);
            $table->char('email_sha256',64);
            $table->char('verification_proof_sha256',64);
            $table->unsignedBigInteger('amount_kopecks');
            $table->char('currency',3)->default('RUB');
            $table->timestampTz('verified_at');
            $table->timestampsTz();
            $table->unique(['product','session_key_hash'],'dz_mail_unique_verified_payment');
        });
    }
    public function down(): void {
        Schema::dropIfExists('dz_mail_verified_payments');
        Schema::dropIfExists('dz_mail_test_completions');
    }
};

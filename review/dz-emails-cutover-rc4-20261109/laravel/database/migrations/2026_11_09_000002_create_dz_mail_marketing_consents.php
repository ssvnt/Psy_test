<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('dz_mail_marketing_consents',function(Blueprint $table):void {
            $table->bigIncrements('id');
            $table->string('product',16);
            $table->char('session_key_hash',64);
            $table->char('email_sha256',64);
            $table->string('consent_version',64);
            $table->string('source',96);
            $table->timestampTz('accepted_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['product','session_key_hash','email_sha256'],'dz_mail_consent_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('dz_mail_marketing_consents'); }
};

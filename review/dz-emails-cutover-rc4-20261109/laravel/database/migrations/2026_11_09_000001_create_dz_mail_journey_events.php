<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('dz_mail_journey_events',function(Blueprint $table):void {
            $table->bigIncrements('id');
            $table->char('event_key',64)->unique();
            $table->string('product',16);
            $table->string('event_kind',48);
            $table->string('session_key',128);
            $table->char('session_key_hash',64);
            $table->timestampTz('scheduled_at');
            $table->string('status',32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('send_started_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->string('error_code',64)->nullable();
            $table->timestampsTz();
            $table->index(['status','scheduled_at']);
            $table->index(['product','session_key_hash']);
        });
    }
    public function down(): void { Schema::dropIfExists('dz_mail_journey_events'); }
};

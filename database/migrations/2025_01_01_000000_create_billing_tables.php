<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bill_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('type');
            $table->string('reference')->unique();
            $table->string('external_reference')->nullable();
            $table->string('status')->default('pending');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_transactions');
    }
};

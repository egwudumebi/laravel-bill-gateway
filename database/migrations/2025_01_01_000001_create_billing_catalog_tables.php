<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bill_categories', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });

        Schema::create('bill_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });

        Schema::create('bill_products', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_id');
            $table->string('name');
            $table->string('category_external_id')->nullable();
            $table->string('biller_external_id')->nullable();
            $table->string('payment_code')->nullable();
            $table->string('currency_code')->nullable();
            $table->boolean('is_airtime')->default(false);
            $table->boolean('is_data')->default(false);
            $table->boolean('is_power')->default(false);
            $table->boolean('is_tv')->default(false);
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_products');
        Schema::dropIfExists('bill_providers');
        Schema::dropIfExists('bill_categories');
    }
};

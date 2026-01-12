<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bill_products', function (Blueprint $table) {
            if (! Schema::hasColumn('bill_products', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo')->default(0)->after('currency_code');
            }
            if (! Schema::hasColumn('bill_products', 'fee_kobo')) {
                $table->unsignedBigInteger('fee_kobo')->default(0)->after('amount_kobo');
            }
        });

        // Optional backfill from decimal columns if they exist
        if (Schema::hasColumn('bill_products', 'amount')) {
            DB::statement("UPDATE bill_products SET amount_kobo = ROUND(amount * 100) WHERE amount_kobo = 0 OR amount_kobo IS NULL");
        }
        if (Schema::hasColumn('bill_products', 'fee')) {
            DB::statement("UPDATE bill_products SET fee_kobo = ROUND(fee * 100) WHERE fee_kobo = 0 OR fee_kobo IS NULL");
        }
    }

    public function down(): void
    {
        Schema::table('bill_products', function (Blueprint $table) {
            if (Schema::hasColumn('bill_products', 'fee_kobo')) {
                $table->dropColumn('fee_kobo');
            }
            if (Schema::hasColumn('bill_products', 'amount_kobo')) {
                $table->dropColumn('amount_kobo');
            }
        });
    }
};

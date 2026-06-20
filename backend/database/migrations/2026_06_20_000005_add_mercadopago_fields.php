<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->string('gateway', 20)->default('ASAAS')->after('asaas_subscription_id');
            $table->string('mp_customer_id', 80)->nullable()->after('gateway');
            $table->string('mp_subscription_id', 80)->nullable()->after('mp_customer_id');
        });

        Schema::table('cobrancas', function (Blueprint $table) {
            $table->string('gateway', 20)->default('ASAAS')->after('status');
            $table->string('mp_payment_id', 80)->nullable()->after('asaas_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'mp_customer_id', 'mp_subscription_id']);
        });
        Schema::table('cobrancas', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'mp_payment_id']);
        });
    }
};

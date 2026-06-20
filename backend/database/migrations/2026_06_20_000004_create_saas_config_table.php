<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_config', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_preferido', 20)->default('ASAAS'); // ASAAS | MERCADOPAGO
            $table->text('asaas_api_key')->nullable();
            $table->text('asaas_webhook_token')->nullable();
            $table->text('mp_access_token')->nullable();
            $table->text('mp_public_key')->nullable();
            $table->text('mp_webhook_secret')->nullable();
            $table->string('mp_ambiente', 20)->default('sandbox'); // sandbox | producao
            $table->timestampTz('atualizado_em')->useCurrent()->useCurrentOnUpdate();
        });

        // Seed com linha singleton vazia
        DB::table('saas_config')->insert([
            'gateway_preferido' => 'ASAAS',
            'atualizado_em'     => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_config');
    }
};

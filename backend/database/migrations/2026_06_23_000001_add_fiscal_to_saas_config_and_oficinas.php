<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->string('provedor_fiscal_padrao', 10)->default('SPEDY');       // SPEDY | FOCUS
            $table->string('emissao_fiscal_modo_padrao', 12)->default('MANUAL');  // MANUAL | AUTOMATICO
            $table->text('spedy_master_key_sandbox')->nullable();
            $table->text('spedy_master_key_producao')->nullable();
            $table->text('focus_master_token_homologacao')->nullable();
            $table->text('focus_master_token_producao')->nullable();
        });

        Schema::table('oficinas', function (Blueprint $table) {
            $table->string('provedor_fiscal', 10)->nullable();        // SPEDY | FOCUS | null
            $table->string('emissao_fiscal_modo', 12)->nullable();    // MANUAL | AUTOMATICO | null
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn([
                'provedor_fiscal_padrao', 'emissao_fiscal_modo_padrao',
                'spedy_master_key_sandbox', 'spedy_master_key_producao',
                'focus_master_token_homologacao', 'focus_master_token_producao',
            ]);
        });
        Schema::table('oficinas', function (Blueprint $table) {
            $table->dropColumn(['provedor_fiscal', 'emissao_fiscal_modo']);
        });
    }
};

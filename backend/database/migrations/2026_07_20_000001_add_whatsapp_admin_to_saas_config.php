<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            // Instância própria da Evolution API para a PLATAFORMA (não uma
            // oficina) — usada só pra notificar o admin do SaaS (ex: pagamento
            // recebido). Reaproveita evolution_url/evolution_api_key já
            // existentes na mesma tabela; aqui só a instância e o destino.
            $table->string('whatsapp_admin_instance', 100)->default('mecanicapro-plataforma');
            $table->text('whatsapp_admin_instance_token')->nullable();
            $table->string('whatsapp_admin_numero', 20)->nullable();
            $table->boolean('whatsapp_admin_ativo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_admin_instance', 'whatsapp_admin_instance_token', 'whatsapp_admin_numero', 'whatsapp_admin_ativo']);
        });
    }
};

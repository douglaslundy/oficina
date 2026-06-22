<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alertas de resposta do orçamento que ainda estão no default (sem destinatário
        // e ambas as flags desligadas) passam a avisar cliente e mecânico. Não mexe em
        // alertas que o usuário já personalizou.
        DB::table('alerta_configs')
            ->whereIn('tipo', ['ORCAMENTO_APROVADO', 'ORCAMENTO_RECUSADO'])
            ->where('enviar_cliente', false)
            ->where('enviar_mecanico', false)
            ->whereRaw('COALESCE(jsonb_array_length(destinatarios), 0) = 0')
            ->update(['enviar_cliente' => true, 'enviar_mecanico' => true]);
    }

    public function down(): void
    {
        // Sem rollback de dados (ajuste de configuração).
    }
};

<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito do usuário (2026-09-14): alerta automático quando uma
 * nota nova for emitida pro CNPJ da oficina, evitando reconsultar sempre as
 * mesmas notas.
 *
 * `dist_dfe_ultimo_nsu`: checkpoint de NSU da Distribuição DFe (NFePHP) —
 * a SEFAZ não filtra por data nesse serviço (só por NSU incremental,
 * confirmado em MotorNfe::listarNotasRecebidas()), então esse é o único
 * mecanismo tecnicamente correto de "buscar só o que é novo" pra esse
 * provedor.
 *
 * `notas_terceiro_ultima_verificacao`: usado por Focus/Spedy, cujas APIs
 * aceitam filtro por data (`$desde` do contrato
 * ConsultaNotaTerceiroProvider::listarNotasRecebidas()) — mais próximo do
 * que o usuário pediu ("considerar a data da última nota importada pra
 * frente"), mas tecnicamente inviável pra NFePHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->unsignedBigInteger('dist_dfe_ultimo_nsu')->nullable()->after('proximo_numero_nfce_nfephp');
            $table->timestampTz('notas_terceiro_ultima_verificacao')->nullable()->after('dist_dfe_ultimo_nsu');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['dist_dfe_ultimo_nsu', 'notas_terceiro_ultima_verificacao']);
        });
    }
};

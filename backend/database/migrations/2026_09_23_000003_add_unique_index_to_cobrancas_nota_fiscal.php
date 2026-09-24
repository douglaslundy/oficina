<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defesa em profundidade (auditoria 2026-09-23, round 2): mesmo com
 * AplicarResultadoNotaService agora travando a linha antes de decidir
 * disparar registrarNotaSeExcedente(), a checagem-depois-cria em
 * PlanLimitService continua sem garantia no nível do banco. Índice único
 * parcial garante, mesmo se um outro caminho futuro chamar
 * registrarNotaSeExcedente() sem passar pelo lock, que o banco rejeita a
 * segunda Cobranca duplicada em vez de simplesmente permitir.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX cobrancas_nota_fiscal_tipo_excedente_uniq '
            . "ON cobrancas (nota_fiscal_id, tipo) WHERE nota_fiscal_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cobrancas_nota_fiscal_tipo_excedente_uniq');
    }
};

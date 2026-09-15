<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap cross-provider achado 2026-09-14 (TAREFAS.md): `codigoIbgeTomador`
 * em NfeService::montarNotaData() sempre vinha de Configuracao (a própria
 * oficina) — `clientes` nunca teve o código IBGE do próprio cliente, então
 * qualquer cliente de outro município saía com cMun/codigo_municipio
 * errado no documento fiscal, divergente de UF/cidade (que já usam o dado
 * real do cliente). O ViaCEP já devolve o campo `ibge` na resposta — só não
 * era capturado no cadastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('codigo_ibge', 10)->nullable()->after('uf');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('codigo_ibge');
        });
    }
};

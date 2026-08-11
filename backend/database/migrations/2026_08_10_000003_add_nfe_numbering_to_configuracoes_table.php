<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração própria da NF-e emitida via NFePHP/sped-nfe — separada de
 * proximo_numero_nf (Spedy/Focus) e proximo_numero_dps (NFS-e nacional,
 * Etapa C1). Mesmo raciocínio da migration 2026_08_04_000001: reaproveitar
 * o contador de outro sistema fiscal colidiria numerações de documentos
 * legalmente distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->string('serie_nfe', 3)->default('1');
            $table->integer('proximo_numero_nfe')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['serie_nfe', 'proximo_numero_nfe']);
        });
    }
};

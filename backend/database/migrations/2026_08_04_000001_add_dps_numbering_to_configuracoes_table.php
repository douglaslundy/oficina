<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração própria da DPS (Declaração de Prestação de Serviços) do motor
 * NFePHP/NFS-e nacional — separada de serie_nf/proximo_numero_nf, que
 * pertencem à numeração de NFS-e do Spedy/Focus. O Id da DPS (composto por
 * CNPJ+município+série+número via IdGenerator::generateDpsId) precisa ser
 * único por documento; reaproveitar o contador do Spedy/Focus colidiria
 * numerações de dois sistemas fiscais distintos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->string('serie_dps', 5)->default('1');
            $table->integer('proximo_numero_dps')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['serie_dps', 'proximo_numero_dps']);
        });
    }
};

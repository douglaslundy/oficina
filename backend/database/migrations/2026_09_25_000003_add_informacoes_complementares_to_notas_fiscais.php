<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot do texto de dados adicionais efetivamente enviado na emissão
 * (Simples + placa/modelo/KM + texto da OS). Necessário porque Spedy/Focus nem
 * sempre devolvem esse campo no XML/PDF; o PDF local usa este valor como
 * fallback quando o XML não traz infCpl/xInfComp (NFEPHP traz sempre).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->text('informacoes_complementares')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropColumn('informacoes_complementares');
        });
    }
};

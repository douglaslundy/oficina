<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // Nula por padrão de propósito — nunca preenchida automaticamente.
            // NULL = "não sabemos" (bloqueia emissão de NF-e/CT-e/MDF-e pra
            // cliente PJ até alguém decidir); string vazia jamais é gravada
            // aqui, só null ou um valor real. Ver
            // IndicadorIeDestinatarioResolver::resolver() pra regra completa.
            $table->string('inscricao_estadual', 20)->nullable()->after('cpf_cnpj');
            // Cliente PJ explicitamente isento de IE (ex.: MEI, produtor
            // rural sem inscrição) — distinto de "ainda não cadastramos a
            // IE dele". Sem isso, um cliente PJ isento ficaria bloqueado pra
            // sempre por falta de IE que ele legitimamente não tem.
            $table->boolean('ie_isento')->default(false)->after('inscricao_estadual');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['inscricao_estadual', 'ie_isento']);
        });
    }
};

<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O grupo prest.end da DPS (NFS-e nacional) exige logradouro/número/bairro
 * separados (TCEndereco, vendor/nfse-nacional/nfse-php/references/schemas/
 * tiposComplexos_v1.01.xsd: xLgr, nro e xBairro sem minOccurs="0", ou seja,
 * obrigatórios se o grupo end for enviado). Configuracao.endereco é texto
 * livre único — sem forma segura de decompor automaticamente sem risco de
 * gerar dado fiscal errado. Campos novos e opcionais: quem já tinha
 * endereco preenchido continua funcionando (prest.end simplesmente não é
 * enviado até logradouro+numero+bairro existirem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->string('logradouro', 150)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('bairro', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['logradouro', 'numero', 'bairro']);
        });
    }
};

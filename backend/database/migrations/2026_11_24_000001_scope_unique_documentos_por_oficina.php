<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** tabela => coluna que era UNIQUE na tabela inteira e passa a ser por oficina. */
    private const COLUNAS = [
        'clientes' => 'cpf_cnpj',
        'produtos' => 'sku',
        'usuarios' => 'cpf',
    ];

    public function up(): void
    {
        // SaaS multi-tenant: o mesmo cliente (CPF/CNPJ), o mesmo SKU ou o mesmo
        // funcionário (CPF) pode existir em oficinas diferentes. Antes, o
        // cadastro numa oficina bloqueava a outra — e a mensagem "já cadastrado"
        // revelava que o documento existe em outra oficina. E-mail de usuário
        // continua único global: o login é por e-mail, sem escolher oficina.
        foreach (self::COLUNAS as $tabela => $coluna) {
            Schema::table($tabela, function (Blueprint $table) use ($tabela, $coluna) {
                $table->dropUnique("{$tabela}_{$coluna}_unique");
                $table->unique(['oficina_id', $coluna]);
            });

            // NULL é distinto num índice composto: registros sem oficina
            // (ex.: superadmin do SaaS) seguem únicos entre si por índice parcial.
            DB::statement("CREATE UNIQUE INDEX {$tabela}_{$coluna}_sem_oficina_unique ON {$tabela} ({$coluna}) WHERE oficina_id IS NULL");
        }
    }

    public function down(): void
    {
        // Falha de propósito se já houver o mesmo documento em duas oficinas.
        foreach (self::COLUNAS as $tabela => $coluna) {
            DB::statement("DROP INDEX IF EXISTS {$tabela}_{$coluna}_sem_oficina_unique");
            Schema::table($tabela, function (Blueprint $table) use ($coluna) {
                $table->dropUnique(['oficina_id', $coluna]);
                $table->unique($coluna);
            });
        }
    }
};

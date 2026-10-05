<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolução de compra (NF-e finNFe=4 referenciando a NF-e de entrada) + controle
 * do que já foi devolvido, e preferências de impressão do cupom (NFC-e / não fiscal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_fiscais', function (Blueprint $table) {
            // NORMAL | DEVOLUCAO — finNFe 1 ou 4.
            $table->string('finalidade', 10)->default('NORMAL');
            $table->string('chave_referenciada', 44)->nullable(); // NFref/refNFe
            $table->uuid('nota_entrada_id')->nullable();
        });

        Schema::table('notas_fiscais_itens', function (Blueprint $table) {
            $table->uuid('nota_entrada_item_id')->nullable()->index();
        });

        Schema::table('notas_entrada_itens', function (Blueprint $table) {
            // Quantidade já retirada do estoque por "devolução ao fornecedor".
            $table->integer('qtd_devolvida_estoque')->default(0);
        });

        Schema::table('configuracoes', function (Blueprint $table) {
            // 80MM (térmica) | A4 — largura do cupom NFC-e / não fiscal.
            $table->string('impressora_cupom', 10)->default('80MM');
            // FISCAL (DANFE NFC-e) | NAO_FISCAL (cupom sem valor fiscal, só dados da venda).
            $table->string('tipo_cupom', 12)->default('FISCAL');
            // Abre o cupom pra impressão automaticamente após emitir/concluir.
            $table->boolean('imprimir_automaticamente')->default(false);
            // Lei 12.741/2012: % médio aproximado de tributos (do contador/IBPT) pra informar
            // o valor no XML da NFC-e (NFePHP) e no cupom. Nulo = não informa.
            $table->decimal('percentual_tributos_aproximados', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', fn (Blueprint $t) => $t->dropColumn(['impressora_cupom', 'tipo_cupom', 'imprimir_automaticamente', 'percentual_tributos_aproximados']));
        Schema::table('notas_entrada_itens', fn (Blueprint $t) => $t->dropColumn('qtd_devolvida_estoque'));
        Schema::table('notas_fiscais_itens', fn (Blueprint $t) => $t->dropColumn('nota_entrada_item_id'));
        Schema::table('notas_fiscais', fn (Blueprint $t) => $t->dropColumn(['finalidade', 'chave_referenciada', 'nota_entrada_id']));
    }
};

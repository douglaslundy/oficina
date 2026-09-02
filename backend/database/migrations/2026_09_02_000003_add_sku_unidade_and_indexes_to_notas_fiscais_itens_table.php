<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notas_fiscais_itens', function (Blueprint $table) {
            // Snapshot do SKU e da unidade do produto no momento da emissão —
            // mesmo princípio de `descricao`/`ncm` (o produto pode mudar depois,
            // a nota fiscal não). Antes disso os providers mandavam o UUID do
            // produto como `codigo_produto` e a unidade fixa em 'UN'.
            $table->string('sku', 30)->nullable()->after('produto_id');
            $table->string('unidade', 10)->nullable()->after('descricao');

            $table->index('nota_fiscal_id');
            $table->index('oficina_id');
        });
    }

    public function down(): void
    {
        Schema::table('notas_fiscais_itens', function (Blueprint $table) {
            $table->dropIndex(['nota_fiscal_id']);
            $table->dropIndex(['oficina_id']);
            $table->dropColumn(['sku', 'unidade']);
        });
    }
};

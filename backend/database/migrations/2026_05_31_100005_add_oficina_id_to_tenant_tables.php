<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'usuarios',
            'clientes',
            'produtos',
            'ordens_servico',
            'os_itens',
            'movimentacoes_estoque',
            'notas_fiscais',
            'configuracoes',
            'agendamentos',
            'password_reset_tokens_custom',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignUuid('oficina_id')
                    ->nullable()
                    ->constrained('oficinas')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'usuarios',
            'clientes',
            'produtos',
            'ordens_servico',
            'os_itens',
            'movimentacoes_estoque',
            'notas_fiscais',
            'configuracoes',
            'agendamentos',
            'password_reset_tokens_custom',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign([$table . '_oficina_id_foreign'] ?? ['oficina_id']);
                $blueprint->dropColumn('oficina_id');
            });
        }
    }
};

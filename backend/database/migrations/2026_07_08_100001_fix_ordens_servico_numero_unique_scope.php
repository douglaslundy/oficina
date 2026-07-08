<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // numero é calculado por oficina (OrdemServico::boot(): max('numero'),
        // escopado por HasTenantScope, + 1), mas a constraint era única na
        // tabela inteira — a primeira OS de qualquer oficina nova calcula
        // numero=1 e colide com o numero=1 de qualquer oficina mais antiga,
        // quebrando a criação da primeira OS de todo cliente novo.
        // Correção: unicidade por (oficina_id, numero), como o resto do
        // sistema já faz para dados por tenant.
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropUnique('ordens_servico_numero_unique');
            $table->unique(['oficina_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropUnique(['oficina_id', 'numero']);
            $table->unique('numero');
        });
    }
};

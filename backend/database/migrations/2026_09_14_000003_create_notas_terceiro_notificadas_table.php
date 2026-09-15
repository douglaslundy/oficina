<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência do alerta de "nota nova emitida pro CNPJ da oficina"
 * (comando `nfe:verificar-notas-recebidas`): sem isso, uma nota recebida mas
 * ainda não importada seria alertada de novo a cada execução agendada
 * (hourly), porque nem toda API de provedor filtra por data
 * (FocusNfeProvider::listarNotasRecebidas() sempre devolve a lista inteira —
 * ver comentário lá). Uma linha aqui = "esta nota já gerou alerta uma vez",
 * independente de já ter sido importada ou não.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_terceiro_notificadas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('oficina_id');
            $table->string('chave_acesso', 44);
            $table->string('fornecedor_nome', 150)->nullable();
            $table->decimal('valor_total', 10, 2)->nullable();
            $table->date('data_emissao')->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->unique(['oficina_id', 'chave_acesso']);
            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_terceiro_notificadas');
    }
};

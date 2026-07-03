<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_entrada', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('numero_nf', 20)->nullable();
            $table->string('serie', 5)->nullable();
            $table->string('chave_acesso', 44)->nullable();
            $table->string('fornecedor_nome', 150)->nullable();
            $table->string('fornecedor_cnpj', 18)->nullable();
            $table->decimal('valor_total', 10, 2)->default(0);
            $table->date('data_emissao')->nullable();
            $table->text('xml_original')->nullable();
            $table->uuid('usuario_id')->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('set null');
            // NULL é tratado como distinto pelo Postgres — várias notas sem chave não colidem entre si.
            $table->unique(['oficina_id', 'chave_acesso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_entrada');
    }
};

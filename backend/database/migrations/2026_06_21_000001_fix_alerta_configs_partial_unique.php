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
        // O índice criado por `unique()->where()` no Blueprint NÃO vira parcial no
        // Postgres — o grammar do Laravel ignora a cláusula where, gerando um unique
        // TOTAL em (oficina_id, tipo). Isso impede criar alertas customizados de um
        // tipo que já existe como pré-definido (duplicate key). Recriamos como índice
        // único PARCIAL de verdade: a unicidade só vale para os pré-definidos.
        Schema::table('alerta_configs', function (Blueprint $table) {
            $table->dropUnique('alerta_configs_oficina_id_tipo_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX alerta_configs_oficina_tipo_predef_unique '
            . 'ON alerta_configs (oficina_id, tipo) WHERE pre_definido = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS alerta_configs_oficina_tipo_predef_unique');

        Schema::table('alerta_configs', function (Blueprint $table) {
            $table->unique(['oficina_id', 'tipo']);
        });
    }
};

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
        Schema::create('categoria_padrao_fiscal', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id');
            $table->string('categoria', 40);
            $table->string('ncm', 8)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
            $table->unique(['oficina_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_padrao_fiscal');
    }
};

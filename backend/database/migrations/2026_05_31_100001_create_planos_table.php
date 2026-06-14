<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('planos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 60)->notNull();
            $table->decimal('preco_mensal', 10, 2)->notNull();
            $table->integer('limite_usuarios')->default(-1); // -1 = unlimited
            $table->integer('limite_os_mes')->default(-1);  // -1 = unlimited
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos');
    }
};

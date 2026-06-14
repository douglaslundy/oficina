<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 120);
            $table->string('email', 120)->unique();
            $table->string('cpf', 11)->unique();
            $table->string('telefone', 15)->nullable();
            $table->string('role', 20)->default('ATENDENTE');
            $table->string('status', 10)->default('ATIVO');
            $table->text('senha_hash');
            $table->timestampTz('ultimo_acesso')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void { Schema::dropIfExists('usuarios'); }
};

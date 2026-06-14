<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobrancas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('oficina_id')->constrained('oficinas')->cascadeOnDelete();
            $table->date('mes_referencia');
            $table->decimal('valor', 10, 2);
            $table->string('status', 20)->default('PENDENTE'); // PENDENTE | PAGA | VENCIDA | CANCELADA
            $table->string('asaas_payment_id', 50)->nullable();
            $table->date('vencimento')->nullable();
            $table->timestampTz('pago_em')->nullable();
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobrancas');
    }
};

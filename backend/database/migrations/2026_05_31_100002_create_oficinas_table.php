<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('oficinas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome', 150);
            $table->string('cnpj', 18)->unique();
            $table->string('slug', 60)->unique();
            $table->foreignUuid('plano_id')->nullable()->constrained('planos')->nullOnDelete();
            $table->string('status', 20)->default('ATIVA'); // ATIVA | INADIMPLENTE | SUSPENSA | CANCELADA
            $table->string('asaas_customer_id', 50)->nullable();
            $table->string('asaas_subscription_id', 50)->nullable();
            $table->string('admin_email', 120)->nullable();
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('atualizado_em')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oficinas');
    }
};

<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('servicos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('oficina_id')->nullable()->index()->constrained('oficinas')->nullOnDelete();
            $table->string('nome', 120);
            $table->decimal('valor_padrao', 10, 2)->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicos');
    }
};

<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('os_pagamentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('os_id')->constrained('ordens_servico')->cascadeOnDelete();
            $table->string('forma_pagamento', 30);
            $table->decimal('valor', 10, 2);
            $table->timestampTz('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_pagamentos');
    }
};

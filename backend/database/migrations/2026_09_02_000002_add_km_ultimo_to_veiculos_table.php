<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('veiculos', function (Blueprint $table) {
            // Última leitura de hodômetro conhecida — atualizada a cada OS criada
            // para o veículo. Serve de base para o aviso de inconsistência de KM
            // na tela de nova OS.
            $table->unsignedInteger('km_ultimo')->nullable()->after('chassi');
        });
    }

    public function down(): void
    {
        Schema::table('veiculos', function (Blueprint $table) {
            $table->dropColumn('km_ultimo');
        });
    }
};

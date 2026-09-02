<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            // Nullable no banco por causa das OS já existentes; a obrigatoriedade
            // ao criar uma OS (tipo OS, não Venda Balcão) é aplicada na validação
            // do OrdemServicoController::store().
            $table->unsignedInteger('km_atual')->nullable()->after('veiculo_placa');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropColumn('km_atual');
        });
    }
};

<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->uuid('veiculo_id')->nullable()->after('cliente_id');
            $table->foreign('veiculo_id')->references('id')->on('veiculos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropForeign(['veiculo_id']);
            $table->dropColumn('veiculo_id');
        });
    }
};

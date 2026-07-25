<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_entrada_itens', function (Blueprint $table) {
            $table->string('ncm_xml', 8)->nullable();
            $table->string('cfop_xml', 4)->nullable();
            $table->string('cest_xml', 7)->nullable();
            $table->smallInteger('origem_xml')->nullable();
            $table->string('cst_csosn_xml', 4)->nullable();
            $table->string('unidade_xml', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas_entrada_itens', function (Blueprint $table) {
            $table->dropColumn([
                'ncm_xml', 'cfop_xml', 'cest_xml', 'origem_xml', 'cst_csosn_xml', 'unidade_xml',
            ]);
        });
    }
};

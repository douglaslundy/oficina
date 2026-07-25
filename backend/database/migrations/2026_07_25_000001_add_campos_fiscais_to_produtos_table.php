<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable(); // NORMAL | ST
            $table->string('fiscal_fonte', 10)->nullable();    // MANUAL | XML | PADRAO
            $table->timestampTz('fiscal_revisado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn([
                'ncm', 'cest', 'origem', 'tributacao_icms', 'fiscal_fonte', 'fiscal_revisado_em',
            ]);
        });
    }
};

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
            $table->string('codigo_barras', 20)->nullable()->after('sku');
            $table->unique(['oficina_id', 'codigo_barras']);
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropUnique(['oficina_id', 'codigo_barras']);
            $table->dropColumn('codigo_barras');
        });
    }
};

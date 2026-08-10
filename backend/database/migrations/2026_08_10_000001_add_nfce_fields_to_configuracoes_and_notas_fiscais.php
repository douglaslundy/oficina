<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->string('serie_nfce', 5)->default('001')->after('serie_nf');
            $table->integer('proximo_numero_nfce')->default(1)->after('proximo_numero_nf');
        });

        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->string('qrcode_url')->nullable()->after('pdf_url');
        });
    }

    public function down(): void
    {
        Schema::table('configuracoes', function (Blueprint $table) {
            $table->dropColumn(['serie_nfce', 'proximo_numero_nfce']);
        });
        Schema::table('notas_fiscais', function (Blueprint $table) {
            $table->dropColumn('qrcode_url');
        });
    }
};

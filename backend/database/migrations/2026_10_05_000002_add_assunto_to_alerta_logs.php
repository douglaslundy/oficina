<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Assunto do e-mail no histórico de mensagens (WhatsApp não tem assunto). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerta_logs', function (Blueprint $table) {
            $table->string('assunto', 200)->nullable()->after('canal');
        });
    }

    public function down(): void
    {
        Schema::table('alerta_logs', fn (Blueprint $table) => $table->dropColumn('assunto'));
    }
};

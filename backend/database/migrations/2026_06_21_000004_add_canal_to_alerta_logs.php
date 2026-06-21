<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerta_logs', function (Blueprint $table) {
            $table->string('canal', 10)->default('WHATSAPP')->after('tipo'); // WHATSAPP | EMAIL
        });

        // E-mails (e listas) excedem os 30 chars usados para telefone.
        DB::statement('ALTER TABLE alerta_logs ALTER COLUMN destinatario TYPE varchar(255)');
    }

    public function down(): void
    {
        Schema::table('alerta_logs', function (Blueprint $table) {
            $table->dropColumn('canal');
        });
        DB::statement('ALTER TABLE alerta_logs ALTER COLUMN destinatario TYPE varchar(30)');
    }
};

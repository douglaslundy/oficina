<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->string('tipo', 20)->default('OS')->after('numero');
            $table->uuid('cliente_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->dropColumn('tipo');
            $table->uuid('cliente_id')->nullable(false)->change();
        });
    }
};

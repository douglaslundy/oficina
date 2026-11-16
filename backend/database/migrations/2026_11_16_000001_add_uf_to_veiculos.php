<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('veiculos', function (Blueprint $table) {
            // UF de emplacamento (NF-e transp/veicTransp/UF). NULL = pendente:
            // veículos existentes não têm o dado e nunca é presumido.
            $table->char('uf', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('veiculos', fn (Blueprint $t) => $t->dropColumn('uf'));
    }
};

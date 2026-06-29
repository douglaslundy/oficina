<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->string('evolution_url', 255)->nullable()->after('smtp_ativo');
            $table->text('evolution_api_key')->nullable()->after('evolution_url');
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn(['evolution_url', 'evolution_api_key']);
        });
    }
};

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
            $table->string('smtp_host', 150)->nullable();
            $table->integer('smtp_port')->default(587);
            $table->string('smtp_username', 150)->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_encryption', 10)->nullable(); // tls | ssl | null
            $table->string('smtp_from_address', 150)->nullable();
            $table->string('smtp_from_name', 100)->nullable();
            $table->boolean('smtp_ativo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('saas_config', function (Blueprint $table) {
            $table->dropColumn([
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
                'smtp_encryption', 'smtp_from_address', 'smtp_from_name', 'smtp_ativo',
            ]);
        });
    }
};

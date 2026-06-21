<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_configs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('oficina_id')->unique();
            $table->string('evolution_url', 255)->default('http://192.168.0.115:8081');
            $table->text('evolution_api_key')->nullable();
            $table->string('instance_name', 100)->default('mecanicapro');
            $table->text('instance_token')->nullable();
            $table->boolean('ativo')->default(false);
            $table->timestampTz('criado_em')->useCurrent();
            $table->timestampTz('atualizado_em')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('oficina_id')->references('id')->on('oficinas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_configs');
    }
};

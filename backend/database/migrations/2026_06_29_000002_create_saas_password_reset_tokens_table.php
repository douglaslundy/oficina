<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_password_reset_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('super_admin_id')->index();
            $table->text('token_hash');
            $table->timestampTz('expires_at');
            $table->boolean('usado')->default(false);
            $table->timestampTz('criado_em')->useCurrent();

            $table->foreign('super_admin_id')->references('id')->on('super_admins')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_password_reset_tokens');
    }
};

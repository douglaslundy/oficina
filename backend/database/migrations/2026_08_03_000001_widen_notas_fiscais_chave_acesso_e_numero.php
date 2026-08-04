<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A chave de acesso da NFS-e nacional (infNFSe/@Id, XSD TSIdNFSe) tem 53
        // caracteres ("NFS" + 50 digitos). 50 era insuficiente e derrubava o
        // UPDATE em NotaFiscalController::emitir() para qualquer nota realmente
        // autorizada pelo provedor NFEPHP.
        DB::statement('ALTER TABLE notas_fiscais ALTER COLUMN chave_acesso TYPE varchar(60)');

        // nNFSe pode ter ate 13 digitos; integer (int4) do Postgres so suporta
        // ate 10 digitos. bigInteger mapeia para int8/bigint.
        DB::statement('ALTER TABLE notas_fiscais ALTER COLUMN numero TYPE bigint');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notas_fiscais ALTER COLUMN numero TYPE integer');
        DB::statement('ALTER TABLE notas_fiscais ALTER COLUMN chave_acesso TYPE varchar(50)');
    }
};

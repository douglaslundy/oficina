<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NFS-e nacional: `notas_fiscais.chave_acesso` guardava o `Id` do infNFSe
 * ("NFS" + 50 dígitos). O "NFS" é só exigência do XML (ID começa com letra) —
 * a chave de acesso são os 50 dígitos. Reportado em 2026-09-25 (nota saindo
 * com "NFS313..." na tela/PDF). MotorNfse já passou a gravar só os 50 dígitos;
 * esta migration corrige as notas já emitidas. Seguro: MotorNfse::chaveNfse50()
 * aceita as duas formas, então consulta/cancelamento continuam funcionando.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "UPDATE notas_fiscais SET chave_acesso = substr(chave_acesso, 4) "
            . "WHERE modelo = 'NFS-e' AND chave_acesso ~ '^NFS[0-9]{50}$'"
        );
    }

    public function down(): void
    {
        // Sem volta: o prefixo era um erro de gravação, não um dado a preservar.
    }
};

<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Contracts;

use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;

interface ConsultaNotaTerceiroProvider
{
    /** Consulta uma NF-e emitida contra o CNPJ da oficina pela chave de
     *  acesso (44 dígitos). Manifesta automaticamente como "ciência da
     *  operação" quando a nota existe mas ainda não está completa. */
    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado;

    /** Lista notas recebidas já sincronizadas pelo provedor, mais recentes
     *  primeiro. $desde filtra por data de emissão quando informado.
     *  @return list<\App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo>
     *  @throws \RuntimeException quando o provedor falha (HTTP de erro) —
     *  nunca deve devolver `[]` silenciosamente pra isso, só pra "de fato
     *  não tem nota nenhuma". O chamador precisa distinguir os dois casos
     *  (ver EntradaNfController::recebidas()). */
    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array;
}

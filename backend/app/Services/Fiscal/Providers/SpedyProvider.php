<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use Illuminate\Support\Facades\Http;

class SpedyProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterKey,
        private readonly ?string $emissorToken = null,
        private readonly ?string $emissorExternoId = null,
    ) {}

    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->masterKey])
            ->post("{$this->baseUrl}/companies", $this->montarPayloadEmpresa($e));

        if ($resp->failed()) {
            return RegistroResultado::erro($resp->json('message') ?? 'Erro ao registrar emissor na Spedy.');
        }

        $id  = (string) $resp->json('id');
        $key = (string) ($resp->json('apiCredentials.apiKey') ?? '');

        return RegistroResultado::ok($id, $key);
    }

    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->masterKey])
            ->attach('file', $pfxBinary, 'certificado.pfx')
            ->post("{$this->baseUrl}/companies/{$this->emissorExternoId}/certificates", [
                'password' => $senha,
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Erro ao enviar certificado para a Spedy: ' . ($resp->json('message') ?? ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/service-invoices", $this->montarPayloadNfse($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $resp->json('message') ?? 'Erro na emissão (Spedy).',
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/service-invoices/{$referencia}");

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('message') ?? 'Erro ao consultar (Spedy).', $referencia);
        }

        return $this->resultadoDe($resp->json(), $referencia);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->delete("{$this->baseUrl}/service-invoices/{$referencia}", [
                'justification' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('message') ?? 'Erro ao cancelar (Spedy).', $referencia);
        }

        return EmissaoResultado::cancelada($referencia);
    }

    public function montarPayloadEmpresa(EmissorData $e): array
    {
        return [
            'name'             => $e->nomeFantasia ?? $e->razaoSocial,
            'legalName'        => $e->razaoSocial,
            'federalTaxNumber' => $e->cnpjLimpo(),
            'stateTaxNumber'   => $e->inscricaoEstadual,
            'cityTaxNumber'    => $e->inscricaoMunicipal,
            'email'            => $e->email,
            'phone'            => $e->telefone,
            'address'          => [
                'street'     => $e->logradouro,
                'number'     => $e->numero,
                'district'   => $e->bairro,
                'postalCode' => preg_replace('/\D/', '', $e->cep),
                'additionalInformation' => $e->complemento,
                'city'       => [
                    'code'  => $e->codigoIbge,
                    'name'  => $e->cidade,
                    'state' => $e->uf,
                ],
            ],
            'taxRegime'          => $this->mapRegime($e->regimeTributario),
            'economicActivities' => [
                ['code' => preg_replace('/\D/', '', $e->cnae), 'isMain' => true],
            ],
        ];
    }

    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        return [
            'status'              => 'enqueued',
            'sendEmailToCustomer' => false,
            'description'         => $n->descricao,
            'federalServiceCode'  => $n->codigoServicoFederal,
            'cityServiceCode'     => $n->codigoServicoMunicipal,
            'taxationType'        => 'taxationInMunicipality',
            'receiver'            => [
                'name'             => $n->tomador['nome'],
                'federalTaxNumber' => preg_replace('/\D/', '', $n->tomador['cpf_cnpj']),
                'email'            => $n->tomador['email'] ?? null,
                'address'          => [
                    'street'     => $n->tomador['logradouro'] ?? '',
                    'number'     => $n->tomador['numero'] ?? 'S/N',
                    'district'   => $n->tomador['bairro'] ?? '',
                    'postalCode' => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
                    'city'       => [
                        'code'  => $n->tomador['codigo_ibge'] ?? '',
                        'name'  => $n->tomador['cidade'] ?? '',
                        'state' => $n->tomador['uf'] ?? '',
                    ],
                ],
            ],
            'total' => [
                'invoiceAmount' => $n->valorServicos,
                'issRate'       => $n->aliquotaIss / 100,
                'issAmount'     => round($n->valorServicos * $n->aliquotaIss / 100, 2),
                'issWithheld'   => $n->issRetido,
            ],
        ];
    }

    public function mapStatus(string $spedyStatus): string
    {
        return match ($spedyStatus) {
            'authorized' => 'AUTORIZADA',
            'rejected'   => 'REJEITADA',
            'canceled'   => 'CANCELADA',
            default      => 'PROCESSANDO', // enqueued, processing, etc.
        };
    }

    private function mapRegime(string $regime): string
    {
        $r = strtolower($regime);
        return match (true) {
            str_contains($r, 'simples')   => 'simplesNacional',
            str_contains($r, 'presumido') => 'lucroPresumido',
            str_contains($r, 'real')      => 'lucroReal',
            default                       => 'simplesNacional',
        };
    }

    private function resultadoDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'enqueued'));

        if ($status === 'REJEITADA') {
            $msg = $json['processingDetail']['message'] ?? ($json['message'] ?? 'Rejeitada pela SEFAZ/Prefeitura.');
            return EmissaoResultado::rejeitada($msg, $ref);
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        return EmissaoResultado::autorizada(
            chave: $json['accessKey'] ?? null,
            protocolo: isset($json['number']) ? (string) $json['number'] : null,
            numero: isset($json['number']) ? (string) $json['number'] : null,
            xml: $json['xml'] ?? null,
            pdfUrl: $json['pdfUrl'] ?? null,
            ref: $ref,
        );
    }
}

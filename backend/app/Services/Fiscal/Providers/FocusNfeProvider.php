<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use Illuminate\Support\Facades\Http;

class FocusNfeProvider implements FiscalProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $masterToken,
        private readonly ?string $emissorToken = null,
    ) {}

    private function ambienteProducao(): bool
    {
        return str_contains($this->baseUrl, 'api.focusnfe.com.br');
    }

    public function registrarEmissor(EmissorData $e): RegistroResultado
    {
        // Certificado é enviado junto no cadastro da empresa (ver enviarCertificado/registro combinado no service).
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->post("{$this->baseUrl}/v2/empresas", $this->montarPayloadEmpresa($e));

        if ($resp->failed()) {
            return RegistroResultado::erro($resp->json('mensagem') ?? 'Erro ao registrar empresa na Focus.');
        }

        $id    = (string) ($resp->json('id') ?? $e->cnpjLimpo());
        $token = (string) ($this->ambienteProducao()
            ? ($resp->json('token_producao') ?? '')
            : ($resp->json('token_homologacao') ?? ''));

        return RegistroResultado::ok($id, $token);
    }

    public function enviarCertificado(EmissorData $e, string $pfxBinary, string $senha): void
    {
        // Focus aceita o certificado no cadastro da empresa (base64). Atualiza via PUT na empresa.
        $resp = Http::withBasicAuth($this->masterToken, '')
            ->put("{$this->baseUrl}/v2/empresas/{$e->cnpjLimpo()}", [
                'arquivo_certificado_base64' => base64_encode($pfxBinary),
                'senha_certificado'          => $senha,
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Erro ao enviar certificado para a Focus: ' . ($resp->json('mensagem') ?? ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->post("{$this->baseUrl}/v2/nfse?ref={$nota->referenciaExterna}", $this->montarPayloadNfse($nota));

        if ($resp->status() >= 400) {
            return EmissaoResultado::rejeitada(
                $resp->json('mensagem') ?? ($resp->json('erros.0.mensagem') ?? 'Erro na emissão (Focus).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    public function consultar(string $referencia): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->get("{$this->baseUrl}/v2/nfse/{$referencia}");

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('mensagem') ?? 'Erro ao consultar (Focus).', $referencia);
        }

        return $this->resultadoDe($resp->json(), $referencia);
    }

    public function cancelar(string $referencia, string $motivo): EmissaoResultado
    {
        $resp = Http::withBasicAuth($this->emissorToken ?? $this->masterToken, '')
            ->delete("{$this->baseUrl}/v2/nfse/{$referencia}", [
                'justificativa' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('mensagem') ?? 'Erro ao cancelar (Focus).', $referencia);
        }

        return EmissaoResultado::cancelada($referencia);
    }

    public function montarPayloadEmpresa(EmissorData $e): array
    {
        return [
            'cnpj'                => $e->cnpjLimpo(),
            'nome'                => $e->razaoSocial,
            'nome_fantasia'       => $e->nomeFantasia ?? $e->razaoSocial,
            'inscricao_municipal' => $e->inscricaoMunicipal,
            'inscricao_estadual'  => $e->inscricaoEstadual,
            'regime_tributario'   => $this->mapRegime($e->regimeTributario),
            'email'               => $e->email,
            'telefone'            => $e->telefone,
            'logradouro'          => $e->logradouro,
            'numero'              => $e->numero,
            'complemento'         => $e->complemento,
            'bairro'              => $e->bairro,
            'cep'                 => preg_replace('/\D/', '', $e->cep),
            'municipio'           => $e->cidade,
            'uf'                  => $e->uf,
            'codigo_municipio'    => $e->codigoIbge,
            'habilita_nfse'       => true,
        ];
    }

    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $chaveDoc   = strlen($docTomador) > 11 ? 'cnpj' : 'cpf';

        return [
            'data_emissao' => date('Y-m-d'),
            'tomador'      => [
                $chaveDoc      => $docTomador,
                'razao_social' => $n->tomador['nome'],
                'email'        => $n->tomador['email'] ?? null,
                'endereco'     => [
                    'logradouro'       => $n->tomador['logradouro'] ?? '',
                    'numero'           => $n->tomador['numero'] ?? 'S/N',
                    'bairro'           => $n->tomador['bairro'] ?? '',
                    'cep'              => preg_replace('/\D/', '', $n->tomador['cep'] ?? ''),
                    'codigo_municipio' => $n->tomador['codigo_ibge'] ?? '',
                    'uf'               => $n->tomador['uf'] ?? '',
                ],
            ],
            'servico' => [
                'discriminacao'               => $n->descricao,
                'item_lista_servico'          => $n->codigoServicoFederal,
                'codigo_tributario_municipio' => $n->codigoServicoMunicipal,
                'aliquota'                    => $n->aliquotaIss,
                'iss_retido'                  => $n->issRetido,
                'valor_servicos'              => $n->valorServicos,
            ],
        ];
    }

    public function mapStatus(string $focusStatus): string
    {
        return match ($focusStatus) {
            'autorizado'              => 'AUTORIZADA',
            'cancelado'               => 'CANCELADA',
            'erro_autorizacao',
            'denegado'                => 'REJEITADA',
            default                   => 'PROCESSANDO', // processando_autorizacao
        };
    }

    private function mapRegime(string $regime): string
    {
        $r = strtolower($regime);
        // Focus: 1=Simples Nacional, 2=SN excesso sublimite, 3=Regime Normal
        return match (true) {
            str_contains($r, 'simples') => '1',
            default                     => '3',
        };
    }

    private function resultadoDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'processando_autorizacao'));

        if ($status === 'REJEITADA') {
            return EmissaoResultado::rejeitada(
                $json['mensagem'] ?? ($json['erros'][0]['mensagem'] ?? 'Rejeitada pela Prefeitura.'),
                $ref,
            );
        }
        if ($status === 'PROCESSANDO') {
            return EmissaoResultado::processando($ref);
        }
        if ($status === 'CANCELADA') {
            return EmissaoResultado::cancelada($ref);
        }

        return EmissaoResultado::autorizada(
            chave: $json['codigo_verificacao'] ?? ($json['chave_nfe'] ?? null),
            protocolo: isset($json['numero']) ? (string) $json['numero'] : null,
            numero: isset($json['numero']) ? (string) $json['numero'] : null,
            xml: $json['caminho_xml_nota_fiscal'] ?? null,
            pdfUrl: $json['url'] ?? ($json['caminho_danfse'] ?? null),
            ref: $ref,
        );
    }
}

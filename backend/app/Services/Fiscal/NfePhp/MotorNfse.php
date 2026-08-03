<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\NotaFiscalData;
use Illuminate\Support\Facades\Log;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Dto\Nfse\PedRegEventoData;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\NfseContext;
use Nfse\Nfse;
use Nfse\Support\IdGenerator;

/**
 * Emite/consulta/cancela NFS-e via o pacote nfse-nacional/nfse-php (Sistema
 * Nacional NFS-e / SEFIN Nacional).
 *
 * Todas as afirmações abaixo sobre o comportamento da biblioteca foram
 * confirmadas lendo `vendor/nfse-nacional/nfse-php/src` e os exemplos oficiais
 * em `vendor/nfse-nacional/nfse-php/examples/contribuinte/*.php` nesta sessão
 * (não são suposições vindas só do README) — ver task-6-report.md para o
 * detalhamento completo.
 */
class MotorNfse
{
    public function __construct(
        private readonly CertificadoStore $certificados = new CertificadoStore(),
    ) {}

    public function emitir(NotaFiscalData $nota, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $nota->referenciaExterna);
        }

        try {
            return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado, string $senha) use ($nota, $cfg, $ambiente) {
                $nfse = new Nfse($this->contexto($cfg, $ambiente, $caminhoCertificado, $senha));

                $dps = $this->montarDps($nota, $cfg);

                // Nfse\Service\ContribuinteService::emitir() já assina o XML,
                // envia e faz o parse da resposta; lança Nfse\Http\Exceptions\
                // NfseApiException em caso de erro (não retorna um objeto de
                // erro) — por isso o catch(\Throwable) abaixo.
                $resultado = $nfse->contribuinte()->emitir($dps);

                // Confirmado em examples/contribuinte/emitir.php: a "chave de
                // acesso" da NFS-e nacional É o atributo Id de infNFSe, não um
                // campo "chaveAcesso" separado (o brief tinha assumido
                // $resultado->chaveAcesso, que não existe em NfseData).
                return EmissaoResultado::autorizada(
                    chave: $resultado->infNfse?->id,
                    protocolo: null, // NFS-e nacional não expõe protocolo distinto da chave de acesso
                    numero: $resultado->infNfse?->numeroNfse,
                    xml: $resultado->nfseXml,
                    pdfUrl: null, // DANFSe é obtido sob demanda via downloadDanfse() (Task 7); a API oficial
                                  // de geração do DANFSe será desativada em 01/07/2026 (ver docblock do método)
                    ref: $nota->referenciaExterna,
                );
            });
        } catch (\Throwable $e) {
            Log::warning(
                'NFePHP/NFS-e: falha ao emitir.',
                ['erro' => $e->getMessage(), 'ref' => $nota->referenciaExterna],
            );

            return EmissaoResultado::erro('Falha técnica ao emitir NFS-e via NFePHP: ' . $e->getMessage(), $nota->referenciaExterna);
        }
    }

    /**
     * Extraído como método isolado (sem I/O) para ser testável sem rede nem
     * certificado real — só monta a estrutura de dados.
     *
     * Formato do array confirmado contra
     * vendor/nfse-nacional/nfse-php/src/Dto/Nfse/InfDpsData.php e o exemplo
     * oficial examples/contribuinte/emitir.php (não é uma transcrição cega
     * do brief — ver task-6-report.md para os pontos que precisaram ser
     * corrigidos).
     */
    public function montarDps(NotaFiscalData $nota, Configuracao $cfg): DpsData
    {
        $docTomador = preg_replace('/\D/', '', $nota->tomador['cpf_cnpj'] ?? '') ?? '';
        $chaveDocTomador = strlen($docTomador) > 11 ? 'CNPJ' : 'CPF';
        $serie = '1';
        $numero = '1'; // TODO confirmar: numeração de NFS-e nacional é por competência/prestador — ver se
                        // precisa de contador próprio antes de ir para produção real, não reaproveitar
                        // proximo_numero_nf (que é de Spedy/Focus)

        $idDps = IdGenerator::generateDpsId($cfg->cnpj ?? '', (string) $cfg->codigo_ibge, $serie, $numero);

        return new DpsData([
            '@attributes' => ['versao' => '1.01'], // versão usada nos exemplos oficiais do pacote
            'infDPS' => [
                '@attributes' => ['Id' => $idDps],
                'tpAmb'    => $cfg->ambiente_fiscal === 'PRODUCAO' ? 1 : 2,
                // 'c' (ISO8601 com timezone) é exigido pelo schema ("AAAA-MM-DDThh:mm:ssTZD");
                // o brief usava um formato sem offset de fuso, que o schema rejeitaria.
                'dhEmi'    => now()->format('c'),
                'verAplic' => config('app.version', '1.0.0'),
                'serie'    => $serie,
                'nDPS'     => $numero,
                'dCompet'  => now()->format('Y-m-d'),
                'tpEmit'   => 1, // Prestador
                'cLocEmi'  => (string) $cfg->codigo_ibge,
                'prest'    => [
                    'CNPJ' => preg_replace('/\D/', '', $cfg->cnpj ?? ''),
                    // regTrib: presente no exemplo oficial (examples/contribuinte/emitir.php).
                    // ATENÇÃO: CrtResolver::resolver() devolve a escala do CRT do leiaute
                    // NF-e/ICMS (1=Simples Nacional, 3=Regime Normal) — escala DIFERENTE da
                    // usada por opSimpNac na NFS-e nacional (1=Não Optante, 2=MEI, 3=ME/EPP).
                    // Usar o valor bruto do CrtResolver aqui seria uma inversão silenciosa
                    // (empresa do Simples reportada como "Não Optante" e vice-versa) — por
                    // isso a tradução explícita em regimeTributarioPrestador().
                    'regTrib' => $this->regimeTributarioPrestador($cfg->regime_tributario ?? ''),
                ],
                'toma'     => [$chaveDocTomador => $docTomador, 'xNome' => $nota->tomador['nome'] ?? ''],
                'serv'     => [
                    'locPrest' => ['cLocPrestacao' => (string) $cfg->codigo_ibge],
                    'cServ'    => [
                        'cTribNac'  => $nota->codigoServicoFederal,
                        'cTribMun'  => $nota->codigoServicoMunicipal,
                        'xDescServ' => $nota->descricao,
                    ],
                ],
                'valores' => [
                    'vServPrest' => ['vServ' => $nota->valorServicos],
                    'trib' => ['tribMun' => [
                        'tribISSQN'  => 1, // Operação tributável
                        // Corrigido: o brief tinha "issRetido ? 1 : 2", invertido — 1 é
                        // "Não Retido" e 2 é "Retido pelo Tomador" (Nfse\Enums\TipoRetencaoIssqn).
                        'tpRetISSQN' => $nota->issRetido ? 2 : 1,
                        'pAliq'      => $nota->aliquotaIss,
                    ]],
                ],
            ],
        ]);
    }

    /**
     * Traduz o regime tributário livre da Configuracao para o grupo regTrib
     * da NFS-e nacional, reaproveitando CrtResolver só para a classificação
     * binária "é Simples Nacional ou não" (não seu valor numérico bruto).
     *
     * Como Configuracao.regime_tributario só distingue "Simples Nacional" de
     * outros regimes (não distingue MEI de ME/EPP), assumimos ME/EPP quando
     * optante — ajustar se um campo dedicado para MEI for adicionado depois.
     *
     * @return array{opSimpNac: int, regApTribSN?: int, regEspTrib: int}
     */
    private function regimeTributarioPrestador(string $regimeTributario): array
    {
        $crt = CrtResolver::resolver($regimeTributario);

        if ($crt === 1) {
            return [
                'opSimpNac'   => 3, // Optante - ME/EPP (assumido; Configuracao não distingue MEI)
                'regApTribSN' => 1, // Regime de apuração dos tributos federais e municipal pelo SN (mais comum)
                'regEspTrib'  => 0, // Nenhum
            ];
        }

        return [
            'opSimpNac'  => 1, // Não Optante
            'regEspTrib' => 0, // Nenhum
        ];
    }

    public function consultar(string $referencia, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $referencia);
        }

        try {
            return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado, string $senha) use ($referencia, $cfg, $ambiente) {
                $nfse = new Nfse($this->contexto($cfg, $ambiente, $caminhoCertificado, $senha));

                // Nfse\Service\ContribuinteService::consultar() engole
                // internamente qualquer NfseApiException e devolve null —
                // tanto para "não encontrada" quanto para falha de API. Não
                // há, a partir daqui, como distinguir os dois casos (o brief
                // assumia que uma falha lançaria exceção; na verdade vira null).
                $resultado = $nfse->contribuinte()->consultar($referencia);

                if ($resultado === null) {
                    return EmissaoResultado::erro(
                        'NFS-e não encontrada ou falha ao consultar (a biblioteca não distingue os dois casos).',
                        $referencia,
                    );
                }

                return EmissaoResultado::autorizada(
                    chave: $resultado->infNfse?->id ?? $referencia,
                    protocolo: null,
                    numero: $resultado->infNfse?->numeroNfse,
                    xml: $resultado->nfseXml,
                    pdfUrl: null,
                    ref: $referencia,
                );
            });
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao consultar NFS-e: ' . $e->getMessage(), $referencia);
        }
    }

    /**
     * Cancelamento real via evento 101101 (Nfse\Service\ContribuinteService::cancelar()).
     *
     * Ao contrário do que a pesquisa original (baseada só no README) supunha,
     * o formato do evento de cancelamento ESTÁ confirmado nesta sessão:
     * vendor/nfse-nacional/nfse-php/examples/contribuinte/cancelar.php mostra
     * o payload completo, e Nfse\Dto\Nfse\{PedRegEventoData,InfPedRegData,
     * CancelamentoData} + Nfse\Xml\EventosXmlBuilder confirmam a estrutura
     * (grupo `e101101` com xDesc/cMotivo/xMotivo, tabela TSCodJustCanc em
     * references/schemas/tiposEventos_v1.01.xsd: 1=Erro na Emissão,
     * 2=Serviço não Prestado, 9=Outros). Por isso este método faz a chamada
     * de verdade em vez de devolver o placeholder do brief.
     *
     * Nossa assinatura só recebe um $motivo em texto livre (sem código
     * estruturado), então usamos cMotivo='9' (Outros) e colocamos o texto em
     * xMotivo — se o chamador precisar dos outros dois motivos (Erro na
     * Emissão / Serviço não Prestado), a assinatura de cancelar() precisará
     * de um parâmetro extra no futuro.
     */
    public function cancelar(string $referencia, string $motivo, string $ambiente): EmissaoResultado
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            return EmissaoResultado::erro('Configurações da empresa não encontradas.', $referencia);
        }

        try {
            return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado, string $senha) use ($referencia, $motivo, $cfg, $ambiente) {
                $nfse = new Nfse($this->contexto($cfg, $ambiente, $caminhoCertificado, $senha));

                $cnpjAutor = preg_replace('/\D/', '', $cfg->cnpj ?? '') ?? '';

                $evento = new PedRegEventoData([
                    'versao' => '1.01',
                    'infPedReg' => [
                        'tpAmb'      => $ambiente === 'PRODUCAO' ? 1 : 2,
                        'verAplic'   => config('app.version', '1.0.0'),
                        'dhEvento'   => now()->format('c'),
                        'chNFSe'     => $referencia,
                        'CNPJAutor'  => $cnpjAutor,
                        'tipoEvento' => '101101', // Cancelamento — também forçado internamente por ContribuinteService::cancelar()
                        'e101101' => [
                            'xDesc'   => 'Cancelamento de NFS-e', // valor fixo exigido pelo XSD v1.01 (TE101101)
                            'cMotivo' => '9', // Tabela TSCodJustCanc: "9 - Outros" (nossa interface só tem texto livre)
                            'xMotivo' => $motivo,
                        ],
                    ],
                ]);

                $resposta = $nfse->contribuinte()->cancelar($evento);

                // Nfse\Dto\Http\RegistroEventoResponse só expõe eventoXmlGZipB64
                // (+ metadados); qualquer erro de API já teria lançado
                // NfseApiException antes de chegar aqui (capturado no catch
                // abaixo). Ausência do XML do evento é um sinal de resposta
                // inesperada, não de sucesso.
                if (empty($resposta->eventoXmlGZipB64)) {
                    return EmissaoResultado::erro(
                        'Cancelamento não confirmado: resposta da SEFIN sem XML de evento.',
                        $referencia,
                    );
                }

                return EmissaoResultado::cancelada($referencia);
            });
        } catch (\Throwable $e) {
            Log::warning(
                'NFePHP/NFS-e: falha ao cancelar.',
                ['erro' => $e->getMessage(), 'ref' => $referencia],
            );

            return EmissaoResultado::erro('Falha técnica ao cancelar NFS-e via NFePHP: ' . $e->getMessage(), $referencia);
        }
    }

    /**
     * Baixa os bytes crus do PDF (DANFSe) de uma NFS-e já autorizada, via a
     * API oficial do ambiente nacional.
     *
     * Confirmado em vendor/nfse-nacional/nfse-php/src/Service/ContribuinteService.php:
     * `downloadDanfse(string $chaveAcesso): string` delega para
     * `Nfse\Http\Client\AdnClient::obterDanfse()`, que faz um GET em
     * `/danfse/{chaveAcesso}` (ADN — Ambiente de Dados Nacional, host próprio,
     * diferente do SEFIN usado por emitir/consultar/cancelar) e devolve
     * `$response->getBody()->getContents()` diretamente — ou seja, bytes crus
     * do PDF, sem DTO/wrapper. Isso confirma a suposição do brief.
     *
     * Em caso de erro de rede/API, `AdnClient::handleException()` lança
     * `Nfse\Http\Exceptions\NfseApiException` (um `\Throwable`) — deixamos
     * propagar para o chamador (`NotaFiscalController::pdf()`), que já tem um
     * `catch(\Throwable)` dedicado e responde com um erro HTTP explícito ao
     * usuário, em vez de mascarar a falha aqui.
     *
     * ATENÇÃO (mesmo aviso do docblock de `ContribuinteService::downloadDanfse()`):
     * esta API oficial de geração de DANFSe será descontinuada em 01/07/2026 —
     * quando isso ocorrer, este método precisará gerar o DANFSe localmente em
     * vez de baixá-lo pronto do ambiente nacional.
     */
    public function baixarDanfse(string $chaveAcesso, string $ambiente): string
    {
        $cfg = Configuracao::first();
        if (! $cfg) {
            throw new \RuntimeException('Configurações da empresa não encontradas.');
        }

        return $this->certificados->comoArquivoTemporario($cfg, function (string $caminhoCertificado, string $senha) use ($chaveAcesso, $cfg, $ambiente) {
            $nfse = new Nfse($this->contexto($cfg, $ambiente, $caminhoCertificado, $senha));

            return $nfse->contribuinte()->downloadDanfse($chaveAcesso);
        });
    }

    /**
     * $senha já vem decifrada do callback de comoArquivoTemporario() — não
     * chamamos $this->certificados->obter($cfg) de novo aqui, para não
     * decifrar o mesmo certificado duas vezes por chamada (comoArquivoTemporario()
     * já decifrou pfx+senha juntos para escrever o arquivo temporário).
     */
    private function contexto(Configuracao $cfg, string $ambiente, string $caminhoCertificado, string $senha): NfseContext
    {
        return new NfseContext(
            ambiente: $ambiente === 'PRODUCAO' ? TipoAmbiente::Producao : TipoAmbiente::Homologacao,
            certificatePath: $caminhoCertificado,
            certificatePassword: $senha,
            codigoMunicipio: $cfg->codigo_ibge,
        );
    }
}

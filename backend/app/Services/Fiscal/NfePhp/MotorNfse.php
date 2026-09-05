<?php
declare(strict_types=1);

namespace App\Services\Fiscal\NfePhp;

use App\Models\Configuracao;
use App\Services\Fiscal\CrtResolver;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\NfeService;
use Illuminate\Support\Facades\Log;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Dto\Nfse\NfseData;
use Nfse\Dto\Nfse\PedRegEventoData;
use Nfse\Enums\CodigoStatus;
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
        private readonly NfeService $numeracao = new NfeService(),
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

                $numeroDps = $this->numeracao->proximoNumeroDps();
                $dps = $this->montarDps($nota, $cfg, $ambiente, $numeroDps);

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
     *
     * $ambiente é recebido como parâmetro explícito (mesmo padrão de
     * emitir()/consultar()/cancelar()) em vez de ser relido de
     * $cfg->ambiente_fiscal aqui dentro. As duas leituras coincidem hoje
     * porque FiscalProviderManager::ambienteDaOficina() sempre deriva de
     * Configuracao, mas são duas fontes de verdade independentes — um
     * futuro ambiente por oficina (já modelado em emissores_fiscais)
     * poderia divergê-las e assinar um DPS com tpAmb errado para o
     * endpoint que de fato vai recebê-lo (contexto(), que já usa o
     * parâmetro, não a config).
     */
    public function montarDps(NotaFiscalData $nota, Configuracao $cfg, string $ambiente, int $numeroDps): DpsData
    {
        $docTomador = preg_replace('/\D/', '', $nota->tomador['cpf_cnpj'] ?? '') ?? '';
        $chaveDocTomador = strlen($docTomador) > 11 ? 'CNPJ' : 'CPF';
        // $numeroDps vem de NfeService::proximoNumeroDps() (contador próprio,
        // transacional com lockForUpdate — ver migration 2026_08_04_000001),
        // nunca mais hardcoded: o Id da DPS repetiria a cada emissão e a
        // segunda NFS-e nacional emitida seria rejeitada como duplicata.
        $serie = $cfg->serie_dps ?: '1';
        $numero = (string) $numeroDps;

        $idDps = IdGenerator::generateDpsId($cfg->cnpj ?? '', (string) $cfg->codigo_ibge, $serie, $numero);

        return new DpsData([
            '@attributes' => ['versao' => '1.01'], // versão usada nos exemplos oficiais do pacote
            'infDPS' => [
                '@attributes' => ['Id' => $idDps],
                'tpAmb'    => $ambiente === 'PRODUCAO' ? 1 : 2,
                // 'c' (ISO8601 com timezone) é exigido pelo schema ("AAAA-MM-DDThh:mm:ssTZD");
                // o brief usava um formato sem offset de fuso, que o schema rejeitaria.
                'dhEmi'    => now()->format('c'),
                'verAplic' => config('app.version', '1.0.0'),
                'serie'    => $serie,
                'nDPS'     => $numero,
                'dCompet'  => now()->format('Y-m-d'),
                'tpEmit'   => 1, // Prestador
                'cLocEmi'  => (string) $cfg->codigo_ibge,
                'prest'    => array_filter([
                    'CNPJ' => preg_replace('/\D/', '', $cfg->cnpj ?? ''),
                    // regTrib: presente no exemplo oficial (examples/contribuinte/emitir.php).
                    // ATENÇÃO: CrtResolver::resolver() devolve a escala do CRT do leiaute
                    // NF-e/ICMS (1=Simples Nacional, 3=Regime Normal) — escala DIFERENTE da
                    // usada por opSimpNac na NFS-e nacional (1=Não Optante, 2=MEI, 3=ME/EPP).
                    // Usar o valor bruto do CrtResolver aqui seria uma inversão silenciosa
                    // (empresa do Simples reportada como "Não Optante" e vice-versa) — por
                    // isso a tradução explícita em regimeTributarioPrestador().
                    'regTrib' => $this->regimeTributarioPrestador($cfg->regime_tributario ?? ''),
                    'end'     => $this->enderecoPrestador($cfg),
                ], static fn ($v) => $v !== null),
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
     * MEI é detectado pela mesma string livre (substring "mei", igual
     * CrtResolver::resolver() já faz para decidir CRT=1) — sem campo
     * dedicado no banco. Convenção: digitar algo como "Simples Nacional -
     * MEI" ou só "MEI" em Configuracao.regime_tributario.
     *
     * @return array{opSimpNac: int, regApTribSN?: int, regEspTrib: int}
     */
    private function regimeTributarioPrestador(string $regimeTributario): array
    {
        $crt = CrtResolver::resolver($regimeTributario);

        if ($crt === 1) {
            $ehMei = str_contains(strtolower($regimeTributario), 'mei');

            return [
                'opSimpNac'   => $ehMei ? 2 : 3, // 2=Optante - MEI, 3=Optante - ME/EPP
                'regApTribSN' => 1, // Regime de apuração dos tributos federais e municipal pelo SN (mais comum)
                'regEspTrib'  => 0, // Nenhum
            ];
        }

        return [
            'opSimpNac'  => 1, // Não Optante
            'regEspTrib' => 0, // Nenhum
        ];
    }

    /**
     * Monta o grupo prest.end (Nfse\Dto\Nfse\EnderecoData) quando os 3 campos
     * decompostos exigidos pelo schema estiverem preenchidos — xLgr/nro/
     * xBairro não têm minOccurs="0" em TCEndereco (references/schemas/
     * tiposComplexos_v1.01.xsd), ou seja, são obrigatórios SE o grupo end for
     * enviado. Configuracao.endereco (texto livre único) não é uma fonte
     * segura pra derivar isso — por isso só populamos quando logradouro,
     * numero e bairro (campos novos e opcionais) já foram preenchidos; caso
     * contrário retorna null e prest.end simplesmente não é enviado (o grupo
     * inteiro é opcional em PrestadorData::$endereco).
     *
     * As chaves usadas são os NOMES DE PROPRIEDADE de EnderecoData
     * (codigoMunicipio, cep, logradouro, numero, bairro), não as tags XML —
     * é assim que Nfse\Dto\Dto::normalizeInput() expande o MapFrom com dot
     * notation ('endNac.cMun', 'endNac.CEP') para o array aninhado que o
     * schema espera. Confirmado lendo o construtor de Dto no vendor, não
     * assumido.
     */
    private function enderecoPrestador(Configuracao $cfg): ?array
    {
        $logradouro = trim((string) ($cfg->logradouro ?? ''));
        $numero = trim((string) ($cfg->numero ?? ''));
        $bairro = trim((string) ($cfg->bairro ?? ''));

        if ($logradouro === '' || $numero === '' || $bairro === '') {
            return null;
        }

        return [
            'codigoMunicipio' => (string) $cfg->codigo_ibge,
            'cep'             => preg_replace('/\D/', '', $cfg->cep ?? '') ?: null,
            'logradouro'      => $logradouro,
            'numero'          => $numero,
            'bairro'          => $bairro,
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

                // NFS-e nacional registra cancelamento como um EVENTO
                // separado (101101, o mesmo que cancelar() envia) — não como
                // um cStat de cancelamento no próprio corpo da NFS-e.
                // Nfse\Enums\CodigoStatus só tem variantes de "gerada"
                // (100/101/102/103/107), confirmado lendo o enum no vendor:
                // não existe um cStat de "cancelada" para checar aqui. Por
                // isso é preciso perguntar por eventos 101101 registrados
                // para esta chave.
                //
                // ContribuinteService::listarEventos(string $chaveAcesso,
                // ?int $tipoEvento = null): array (confirmado em
                // vendor/nfse-nacional/nfse-php/src/Service/ContribuinteService.php)
                // delega para SefinClient::listarEventosPorTipo(), que faz
                // GET em "nfse/{chaveAcesso}/eventos/{tipoEvento}" e devolve
                // json_decode(..., true) — um array puro (associativo/de
                // arrays), NÃO uma lista de objetos com propriedades.
                //
                // A lib não distingue "sem eventos" (array vazio, 200) de
                // "erro real" (SefinClient::handleException() sempre lança
                // NfseApiException, mesmo pra "não encontrado") — CORRIGIDO
                // aqui: uma exceção agora vira incerteza (ERRO), nunca mais
                // "tratado como sem cancelamento" silenciosamente. A mesma
                // regra que mapearResultadoConsulta() já aplica pro cStat
                // (nunca chutar um status de sucesso não confirmado) agora
                // vale também pra essa checagem.
                $eventosCancelamento = null;
                $falhaAoListarEventos = null;
                try {
                    $chave = $resultado->infNfse?->id ?? $referencia;
                    $eventosCancelamento = $nfse->contribuinte()->listarEventos($chave, 101101);
                } catch (\Throwable $e) {
                    $falhaAoListarEventos = $e;
                    Log::warning(
                        'NFePHP/NFS-e: falha ao listar eventos 101101 ao consultar — reportado como incerteza (ERRO), não mais como "sem cancelamento".',
                        ['erro' => $e->getMessage(), 'ref' => $referencia],
                    );
                }

                return $this->resultadoAposVerificarCancelamento($resultado, $eventosCancelamento, $falhaAoListarEventos, $referencia);
            });
        } catch (\Throwable $e) {
            return EmissaoResultado::erro('Falha ao consultar NFS-e: ' . $e->getMessage(), $referencia);
        }
    }

    /**
     * Decide o resultado da consulta considerando se foi possível checar
     * eventos de cancelamento — extraído de consultar() pra ser testável
     * sem rede/certificado, mesmo padrão de mapearResultadoConsulta().
     *
     * $falhaAoListarEventos não-nulo significa que não dá pra confirmar se
     * a nota está cancelada ou não — nesse caso o resultado é ERRO, nunca
     * o cStat do corpo da NFS-e (que poderia estar desatualizado se a nota
     * foi cancelada e só não conseguimos confirmar isso agora).
     */
    private function resultadoAposVerificarCancelamento(
        NfseData $resultado,
        ?array $eventosCancelamento,
        ?\Throwable $falhaAoListarEventos,
        ?string $referencia,
    ): EmissaoResultado {
        if ($falhaAoListarEventos !== null) {
            return EmissaoResultado::erro(
                'Não foi possível confirmar se a NFS-e está cancelada (falha ao consultar eventos): '
                    . $falhaAoListarEventos->getMessage(),
                $referencia,
            );
        }

        return $this->mapearResultadoConsulta($resultado, !empty($eventosCancelamento), $referencia);
    }

    /**
     * Mapeamento puro (sem I/O) do resultado de uma consulta para
     * EmissaoResultado — extraído de consultar() para ser testável sem rede
     * nem certificado.
     *
     * $temEventoCancelamento já vem resolvido por quem chama (consultar()),
     * porque descobrir isso exige uma chamada de rede
     * (listarEventos/listarEventosPorTipo) que não pertence a um método puro.
     *
     * Regra central desta etapa fiscal: nunca "chutar" AUTORIZADA para um
     * status que não reconhecemos — por isso o fallback é ERRO, não
     * AUTORIZADA.
     */
    private function mapearResultadoConsulta(NfseData $resultado, bool $temEventoCancelamento, ?string $referencia): EmissaoResultado
    {
        if ($temEventoCancelamento) {
            return EmissaoResultado::cancelada($referencia);
        }

        $statusAutorizados = [
            CodigoStatus::NfseGerada,
            CodigoStatus::NfseSubstituicaoGerada,
            CodigoStatus::NfseDecisaoJudicial,
            CodigoStatus::NfseAvulsa,
            CodigoStatus::NfseMei,
        ];

        $status = $resultado->infNfse?->codigoStatus;

        if ($status !== null && in_array($status, $statusAutorizados, true)) {
            return EmissaoResultado::autorizada(
                chave: $resultado->infNfse?->id ?? $referencia,
                protocolo: null,
                numero: $resultado->infNfse?->numeroNfse,
                xml: $resultado->nfseXml,
                pdfUrl: null,
                ref: $referencia,
            );
        }

        return EmissaoResultado::erro(
            'NFS-e em status não reconhecido (cStat='
                . ($status?->value !== null ? (string) $status->value : 'ausente')
                . '); não é possível classificar com segurança como autorizada.',
            $referencia,
        );
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
     * estruturado) — cMotivo é inferido do texto por palavra-chave
     * (classificarMotivoCancelamento()), com '9'/Outros como default seguro
     * pra qualquer texto que não bata com as outras duas categorias. O texto
     * original sempre vai integralmente em xMotivo, então a classificação
     * errada nunca perde informação — só deixa de marcar 1/2 quando poderia.
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
                            'cMotivo' => $this->classificarMotivoCancelamento($motivo),
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
     * Classifica o texto livre de motivo do cancelamento no código oficial
     * da Tabela TSCodJustCanc (references/schemas/tiposEventos_v1.01.xsd):
     * 1=Erro na Emissão, 2=Serviço não Prestado, 9=Outros (default seguro —
     * nunca inventa 1 ou 2 sem uma palavra-chave clara no texto).
     *
     * Str::ascii() normaliza acentos antes da comparação (usuário pode
     * digitar "não prestado" ou "nao prestado" indiferentemente).
     */
    private function classificarMotivoCancelamento(string $motivo): string
    {
        // Str::ascii() primeiro, strtolower() depois: strtolower() do PHP não
        // é multibyte-safe (não lida com 'Ç'/'Ã' maiúsculos corretamente),
        // então rodar na ordem inversa deixava "SERVIÇO NÃO" sem bater com
        // "servico nao" — achado pelo teste, não hipotético.
        $m = strtolower(\Illuminate\Support\Str::ascii($motivo));

        if (str_contains($m, 'erro') || str_contains($m, 'engano') || str_contains($m, 'equivoco')) {
            return '1'; // Erro na Emissão
        }

        if (str_contains($m, 'nao prestado') || str_contains($m, 'nao realizado') || str_contains($m, 'nao executado')) {
            return '2'; // Serviço não Prestado
        }

        return '9'; // Outros
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

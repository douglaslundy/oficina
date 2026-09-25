<?php
declare(strict_types=1);

namespace App\Services\Fiscal\Providers;

use App\Services\Fiscal\Contracts\ConsultaNotaTerceiroProvider;
use App\Services\Fiscal\Contracts\FiscalProvider;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResultado;
use App\Services\Fiscal\Data\ConsultaNotaTerceiroResumo;
use App\Services\Fiscal\Data\EmissaoResultado;
use App\Services\Fiscal\Data\EmissorData;
use App\Services\Fiscal\Data\NotaFiscalData;
use App\Services\Fiscal\Data\RegistroResultado;
use App\Services\NotaEntradaXmlParser;
use Illuminate\Support\Facades\Http;

class SpedyProvider implements FiscalProvider, ConsultaNotaTerceiroProvider
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
            return RegistroResultado::erro($this->mensagemErroDe($resp, 'Erro ao registrar emissor na Spedy.'));
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
            throw new \RuntimeException('Erro ao enviar certificado para a Spedy: ' . $this->mensagemErroDe($resp, ''));
        }
    }

    public function emitir(NotaFiscalData $nota): EmissaoResultado
    {
        // Modo AUTOMATICO_PROVEDOR: a Spedy calcula CFOP/CST/ICMS/ISS sozinha
        // via POST /v1/orders (nenhum campo fiscal no payload). Confirmado
        // por spike real no sandbox (2026-09-05) — a nota passa por toda a
        // validação fiscal, só bloqueia por falta do certificado A1. Um só
        // caminho pros 3 modelos (NF-e/NFC-e/NFS-e), distinguidos por
        // product.invoiceModel.
        if ($nota->calculoTributarioModo === 'AUTOMATICO_PROVEDOR') {
            return $this->emitirViaOrders($nota);
        }

        if ($nota->modelo === 'NFE') {
            return $this->emitirNfe($nota);
        }

        if ($nota->modelo === 'NFCE') {
            return $this->emitirNfce($nota);
        }

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/service-invoices", $this->montarPayloadNfse($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro na emissão (Spedy).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna, 'service-invoices');
    }

    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado
    {
        // Bug de reconciliação (2026-09-10, TAREFAS.md): GET /{recurso}/{referencia}
        // usando a nossa referência interna sempre dava 404 — a Spedy não conhece
        // esse valor como um ID dela. Confirmado empiricamente no sandbox real:
        // GET ?integrationId=X filtra de verdade pelo campo que montarPayloadNfse()/
        // Nfce()/Nfe() agora mandam na criação (`integrationId`).
        $recurso = $this->recursoPorModelo($modelo);

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/{$recurso}", ['integrationId' => $this->integrationIdDe($referencia)]);

        if ($resp->failed()) {
            // Falha ao CONSULTAR (rede, auth, 5xx) não é o mesmo que "rejeitada
            // pela SEFAZ" — virar REJEITADA aqui já corrompeu 10 NF-e reais em
            // produção com essa mensagem genérica, sem a SEFAZ ter dito nada.
            // Mesma classe de bug já corrigida em MotorNfse::consultar()
            // (Rodada 37): falha de consulta fica PROCESSANDO (retentável pelo
            // polling do frontend e pelo comando agendado), nunca vira um
            // status fiscal substantivo.
            \Illuminate\Support\Facades\Log::warning(
                'Spedy: falha ao consultar status da nota — mantendo PROCESSANDO.',
                ['referencia' => $referencia, 'modelo' => $modelo, 'status_http' => $resp->status(), 'corpo' => $resp->body()],
            );
            return EmissaoResultado::processando($referencia);
        }

        $item = ($resp->json('items') ?? [])[0] ?? null;
        if ($item === null) {
            // Criação assíncrona na Spedy: a nota pode ainda não aparecer na
            // listagem por integrationId no instante da consulta — não é
            // rejeição, é "ainda processando" (próximo poll tenta de novo).
            return EmissaoResultado::processando($referencia);
        }

        return $modelo === 'NFCE'
            ? $this->resultadoNfceDe($item, $referencia, $recurso)
            : $this->resultadoDe($item, $referencia, $recurso);
    }

    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
    {
        // BUG REAL DE PRODUÇÃO (2026-09-14, mesma classe do bug antigo do
        // consultar()): a nossa referência interna nunca é o ID real da
        // Spedy — DELETE direto por ela dá 404 sempre. Confirmado
        // empiricamente no sandbox: DELETE pelo `id` real (achado via
        // GET ?integrationId=) funciona e retorna "Cancelamento da nota
        // fiscal está em processamento.". Busca o id real primeiro.
        $recurso = $this->recursoPorModelo($modelo);

        $lookup = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/{$recurso}", ['integrationId' => $this->integrationIdDe($referencia)]);

        if ($lookup->failed()) {
            return EmissaoResultado::rejeitada($lookup->json('message') ?? 'Erro ao localizar a nota para cancelamento (Spedy).', $referencia);
        }

        $item = ($lookup->json('items') ?? [])[0] ?? null;
        if ($item === null || empty($item['id'])) {
            // Nota emitida antes deste fix nunca foi tagueada com
            // integrationId na Spedy — não tem como localizar o id real
            // automaticamente. Mensagem clara em vez do genérico "Erro ao
            // cancelar", pra não confundir com uma falha de rede/API.
            return EmissaoResultado::rejeitada(
                'Nota não encontrada na Spedy para cancelamento — pode ser anterior à correção de referência (emitida antes de 2026-09-14). Cancele manualmente pelo painel da Spedy.',
                $referencia,
            );
        }

        // Campo confirmado como `reason` na doc (docs.spedy.com.br/api-reference/
        // {nfs-e,nfc-e,nf-e}/cancelar-*.md) — `justification` era um chute anterior,
        // nunca validado em sandbox real, corrigido em sessão anterior.
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->delete("{$this->baseUrl}/{$recurso}/{$item['id']}", [
                'reason' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($this->mensagemErroDe($resp, 'Erro ao cancelar (Spedy).'), $referencia);
        }

        return EmissaoResultado::cancelada($referencia);
    }

    /** Recurso REST por tipo de documento — mesmos 3 recursos usados em emitir()/consultar()/cancelar(). */
    private function recursoPorModelo(string $modelo): string
    {
        return match ($modelo) {
            'NFE'  => 'product-invoices',
            'NFCE' => 'consumer-invoices',
            default => 'service-invoices',
        };
    }

    /**
     * BUG REAL DE PRODUÇÃO (2026-09-14): a Spedy rejeita a criação da nota
     * com HTTP 400 "The field IntegrationId must be a string with a maximum
     * length of 36." — a nossa referência interna é sempre `nf-<uuid>` (39
     * chars: prefixo "nf-" + UUID de 36), estourando o limite. Confirmado
     * batendo direto na Spedy real via tinker (2 NF-e reais rejeitadas antes
     * de qualquer processamento fiscal, minutos depois do deploy do fix de
     * reconciliação). Os últimos 36 chars da referência são sempre o UUID
     * puro (o prefixo "nf-" é sempre 3 chars, vindo de
     * IniciarEmissaoNotaService/NfeService), então pegar os últimos 36
     * funciona pra qualquer referência gerada por este sistema, com ou sem
     * prefixo. Usado tanto ao criar (integrationId no payload) quanto ao
     * consultar (filtro ?integrationId=) — os dois lados precisam mandar o
     * MESMO valor truncado pra Spedy conseguir casar um com o outro.
     */
    private function integrationIdDe(string $referencia): string
    {
        return substr($referencia, -36);
    }

    /**
     * Achado real ao investigar erro de cancelamento (2026-09-14): a Spedy
     * devolve erros 400 no formato `{"errors":[{"message":"...",
     * "path":"..."}]}` — uma chave `message` de nível raiz (o que todo
     * `$resp->json('message')` deste arquivo lia) não existe nesse formato,
     * então toda mensagem real da Spedy virava o fallback genérico. Isso
     * também explica por que `emissores_fiscais.ultimo_erro` da stuntmotos
     * nunca teve detalhe (`registrarEmissor()` tem o mesmo padrão) — a causa
     * real do registro nunca chegou a ser vista.
     */
    private function mensagemErroDe(\Illuminate\Http\Client\Response $resp, string $default): string
    {
        return $resp->json('message') ?? $resp->json('errors.0.message') ?? $default;
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
            // Bug real achado 2026-09-15: `isMain` não existe em
            // `CompanyEconomicActivityDto` — o campo real é `type` (enum
            // `main`/`secondary`). `isMain: true` era ignorado pela Spedy;
            // nenhuma atividade econômica ficava marcada como principal.
            'economicActivities' => [
                ['code' => preg_replace('/\D/', '', $e->cnae), 'type' => 'main'],
            ],
        ];
    }

    /**
     * Bugs reais achados 2026-09-15 (auditoria campo-a-campo contra
     * docs.spedy.com.br/api-reference/nfs-e/criar-nfs-e.md, POST
     * /v1/service-invoices):
     * - `effectiveDate` (data de competência) está no `required` do schema
     *   e NUNCA era mandado — bloqueava toda emissão de NFS-e via Spedy por
     *   campo obrigatório ausente. Esse fluxo nunca tinha comentário de
     *   spike/teste real (ao contrário de NF-e/NFC-e), indício de que nunca
     *   foi validado contra sandbox de verdade.
     * - `status` não existe como campo de request — é "controlado
     *   exclusivamente pela Spedy" segundo a doc; quem decide emitir de
     *   verdade (vs. rascunho) é o booleano `issue` (default `true`, que já
     *   é o comportamento desejado aqui). Removido.
     * - `operationNature` não existe no schema de NFS-e (existe em NF-e/
     *   NFC-e, prescolhido daí por engano) — os campos reais de natureza
     *   são `taxationType`/`federalServiceCode`/`cityServiceCode`, já
     *   enviados. Removido.
     */
    public function montarPayloadNfse(NotaFiscalData $n): array
    {
        return [
            // Bug de reconciliação (2026-09-10, ver TAREFAS.md): sem mandar a
            // nossa referência aqui, consultar() nunca consegue achar a nota
            // de volta na Spedy (ela não conhece a nossa referência interna
            // como um ID dela) — toda nota fica PROCESSANDO pra sempre no
            // nosso banco mesmo já autorizada lá. `integrationId` é o campo
            // que consultar() usa como filtro (?integrationId=) pra achá-la.
            'integrationId'       => $this->integrationIdDe($n->referenciaExterna),
            ...($n->informacoesComplementares !== null ? ['additionalInformation' => $n->informacoesComplementares] : []), // NFS-e: "Informações adicionais" (doc Spedy)
            'effectiveDate'       => now()->toDateString(),
            'sendEmailToCustomer' => false,
            'description'         => $n->descricao,
            'federalServiceCode'  => $n->codigoServicoFederal,
            'cityServiceCode'     => $n->codigoServicoMunicipal,
            'taxationType'        => 'taxationInMunicipality',
            'receiver'            => [
                'name'             => $n->tomador['nome'],
                'federalTaxNumber' => preg_replace('/\D/', '', $n->tomador['cpf_cnpj']),
                'email'            => $n->tomador['email'] ?? null,
                'address'          => $this->enderecoDestinatario($n->tomador),
            ],
            'total' => [
                'invoiceAmount' => $n->valorServicos,
                'issRate'       => $n->aliquotaIss / 100,
                'issAmount'     => round($n->valorServicos * $n->aliquotaIss / 100, 2),
                'issWithheld'   => $n->issRetido,
            ],
        ];
    }

    /**
     * Bloco de endereço do destinatário no formato da Spedy (`receiver.address`).
     * Para NF-e (modelo 55) o endereço do destinatário é OBRIGATÓRIO — sem ele
     * a Spedy rejeita com "Endereço do cliente é obrigatório". A NFS-e sempre
     * mandou este bloco; a NF-e e a NFC-e não mandavam (bug real, homologação
     * 2026-09-11).
     *
     * @param array<string, mixed> $tomador
     * @return array<string, mixed>
     */
    private function enderecoDestinatario(array $tomador): array
    {
        return [
            'street'     => $tomador['logradouro'] ?? '',
            'number'     => $tomador['numero'] ?? 'S/N',
            'district'   => $tomador['bairro'] ?? '',
            'postalCode' => preg_replace('/\D/', '', $tomador['cep'] ?? ''),
            'city'       => [
                'code'  => $tomador['codigo_ibge'] ?? '',
                'name'  => $tomador['cidade'] ?? '',
                'state' => $tomador['uf'] ?? '',
            ],
        ];
    }

    /**
     * Schema confirmado contra docs.spedy.com.br/api-reference/nfc-e/criar-nfc-e.md
     * (2026-09-15): POST /v1/consumer-invoices. A implementação anterior deste
     * método era um payload INFERIDO por analogia com outros métodos (nunca
     * validado contra a doc real — comentário antigo admitia isso) e tinha
     * vários nomes de campo que simplesmente NÃO EXISTEM no schema real:
     * - `productCode` → correto é `code`.
     * - `commercialUnit` → correto é `unit`.
     * - `unitValue`/`grossValue` → corretos são `unitAmount`/`totalAmount`.
     * - `icmsOrigin`/`icmsTaxSituation` soltos no item → precisam ficar
     *   aninhados em `taxes.icms.origin`/`taxes.icms.cst|csosn` (mesmo
     *   formato já usado — corretamente — por montarPayloadNfe()).
     * - `receiver.individualTaxNumber` pra CPF → não existe no schema do
     *   Receiver; só tem `federalTaxNumber`, usado pra CPF e CNPJ igualmente
     *   (confirmado na doc: nenhum campo separado pra pessoa física).
     * - `payments[].value` → correto é `payments[].amount`.
     * - `payments[].method: 'cash'` → não é um valor de enum válido (os
     *   válidos incluem `money`, não `cash`) — trocado por
     *   `mapFormaPagamento()`, já compartilhado com a NF-e.
     * - PIS/COFINS nunca eram mandados — mesmo padrão CST 49 zerado de
     *   montarPayloadNfe() (Simples Nacional paga via DAS unificado, sem
     *   cálculo por operação; nenhuma oficina deste sistema é Regime Normal
     *   ainda). Confirmado na doc que `taxes.pis`/`taxes.cofins` existem com
     *   os mesmos campos (`cst`/`baseTax`/`rate`/`amount`) usados na NF-e.
     * - `itemNumber` nos itens → não existe no schema; removido (a NF-e
     *   também nunca mandou).
     *
     * Explica por que nenhuma NFC-e via Spedy chegou a autorizar até agora
     * (Rodada 39 continuação 2, TAREFAS.md): o payload nunca tinha sido
     * validado contra a doc real, só copiado (errado) do padrão usado nos
     * outros métodos.
     *
     * **Confirmado ao vivo em homologação (2026-09-15)**: com este payload
     * corrigido, a Spedy aceita a requisição (`enqueued`/PROCESSANDO, sem
     * nenhum erro de schema) — evolução real em relação ao payload antigo,
     * que rejeitava IMEDIATAMENTE com erro de deserialização
     * (`"cash"` não é um `SefazInvoicePaymentMethod` válido). Consultando o
     * resultado, a nota vem REJEITADA com motivo genuinamente fiscal, não
     * mais de schema: **"TokenId e CSC da NFC-e são obrigatórios. Informe
     * esses dados na configuração da empresa."** — CSC (Código de Segurança
     * do Contribuinte) é uma credencial que a oficina precisa obter junto à
     * SEFAZ do seu estado (mesma exigência já documentada pro motor NFePHP,
     * ver TAREFAS.md "sobras"), configurada do lado da Spedy via
     * `PUT /v1/companies/{id}/settings` (bloco `consumerInvoice`, campos
     * `tokenId`/`csc` — confirmado que o endpoint existe, estrutura exata
     * dos campos não documentada em detalhe, precisa de teste real quando
     * alguma oficina tiver o CSC em mãos). **Não implementado ainda** —
     * bloqueio real de credencial externa, não um bug de código.
     */
    public function montarPayloadNfce(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj'] ?? '') ?: '';
        $crt        = \App\Services\Fiscal\CrtResolver::resolver($n->regimeTributario);
        $campoIcms  = $crt === 1 ? 'csosn' : 'cst'; // CRT=1 (Simples Nacional) usa CSOSN, senão CST
        $valorTotal = round(array_sum(array_map(
            fn ($item) => (float) $item['quantidade'] * (float) $item['valor_unitario'],
            $n->itens
        )), 2);
        // Corrigido 2026-09-23 (auditoria fiscal completa): este payload
        // nunca mandava a IE do destinatário — mesma classe de gap do bug
        // original de NF-e (cStat=232), só que no fluxo de NFC-e. Menos
        // frequente na prática (maioria dos compradores de balcão é PF),
        // o que provavelmente é por que nunca apareceu em produção, mas o
        // schema (`CreateConsumerInvoiceDto.receiver`) é o mesmo
        // SefazInvoiceReceiverDto da NF-e, com o mesmo `stateTaxNumber`.
        $indicadorIe = $n->tomador['indicador_ie'] ?? 9;

        return array_filter([
            // integrationId: mesmo fix de reconciliação de montarPayloadNfse()
            // — ver comentário lá.
            'integrationId'   => $this->integrationIdDe($n->referenciaExterna),
            'additionalInformation' => $n->informacoesComplementares, // NF-e/NFC-e: infCpl (doc Spedy)
            'series'          => $n->serieNf,
            'number'          => $n->numeroAlocado !== null ? (int) $n->numeroAlocado : null,
            // NFC-e é por definição venda a consumidor final — sempre true,
            // nunca condicionado a IE (isso nunca foi o bug aqui, só na
            // NF-e; ver montarPayloadNfe()).
            'isFinalCustomer' => true,
            // Operação presencial (balcão) — mesma regra de montarPayloadNfe().
            'presenceType'    => 'presence',
            'operationNature' => $n->naturezaOperacao,
            'receiver' => array_filter(array_merge(
                [
                    'name'             => $n->tomador['nome'],
                    'federalTaxNumber' => $docTomador,
                    'stateTaxNumber'   => $indicadorIe === 1 ? ($n->tomador['inscricao_estadual'] ?? null) : null,
                ],
                // NFC-e a consumidor final costuma dispensar endereço (venda de
                // balcão sem cadastro). Só manda o bloco quando o cliente tem
                // um logradouro cadastrado — senão a Spedy pode recusar um
                // address todo vazio numa nota que passaria sem ele.
                empty($n->tomador['logradouro'])
                    ? []
                    : ['address' => $this->enderecoDestinatario($n->tomador)],
            ), fn ($v) => $v !== null),
            'items' => array_map(fn (int $i, array $item) => [
                'code'        => $item['sku'] ?? $item['produto_id'],
                'description' => $item['descricao'],
                'ncm'         => $item['ncm'],
                // Obrigatório quando o CST/CSOSN indica ST (rejeição real
                // "806: operação com ICMS-ST sem CEST", homologação
                // 2026-09-10). Vem de produtos.cest via NfeService.
                'cest'        => $item['cest'] ?? null,
                'cfop'        => (int) $item['cfop'],
                'unit'        => $item['unidade'] ?? 'UN',
                'quantity'    => (float) $item['quantidade'],
                'unitAmount'  => (float) $item['valor_unitario'],
                'totalAmount' => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                // Campos tributáveis (uTrib/qTrib/vUnTrib) — ver comentário em
                // montarPayloadNfe(). Sem unidade de conversão nesta v1, o
                // tributável é sempre igual ao comercial.
                'unitTax'       => $item['unidade'] ?? 'UN',
                'quantityTax'   => (float) $item['quantidade'],
                'unitTaxAmount' => (float) $item['valor_unitario'],
                'makeupTotal'   => true,
                'taxes' => [
                    'icms' => [
                        'origin'   => (int) $item['origem'],
                        $campoIcms => (int) $item['cst_csosn'],
                    ],
                    'pis'    => ['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0],
                    'cofins' => ['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0],
                ],
            ], array_keys($n->itens), $n->itens),
            'payments' => [[
                'method' => $this->mapFormaPagamento($n->formaPagamento),
                'amount' => $valorTotal,
            ]],
        ], fn ($v) => $v !== null);
    }

    /**
     * Schema confirmado contra docs.spedy.com.br/api-reference/nf-e/criar-nf-e.md
     * (2026-09-04): POST /v1/product-invoices. `cfop` é **integer** e `cst`/
     * `csosn` ficam em campos distintos dentro de `taxes.icms` — dai o
     * CrtResolver aqui. (Nota 2026-09-23: a afirmação original de que
     * montarPayloadNfce() usava formato diferente — `cfop` string e um único
     * `icmsTaxSituation` — estava desatualizada; os dois métodos usam o
     * mesmo `SefazInvoiceItemDto` desde a correção de 2026-09-15.)
     *
     * Testado contra sandbox real em 2026-09-10: a SEFAZ rejeitou com 3 erros
     * de schema XML ("Id attribute invalid", "nNF valor '0' inválido",
     * "elemento 'prod' com filho inválido 'qTrib', esperado 'cBarraTrib,
     * uTrib'") porque o payload não mandava `quantityTax`/`unitTaxAmount`
     * (uTrib/qTrib/vUnTrib no XML da NF-e) — **obrigatórios** no schema real
     * `SefazInvoiceItemDto` (confirmado via docs.spedy.com.br), junto com
     * `makeupTotal` (indTot). Sem eles a Spedy gera um XML incompleto que a
     * própria SEFAZ rejeita na validação estrutural, antes de qualquer
     * validação fiscal de conteúdo. Mesmo schema de item usado por
     * montarPayloadNfce() (SefazInvoiceItemDto é compartilhado).
     *
     * Depois de corrigir os campos tributáveis acima, sobrou "nNF valor '0'
     * inválido" + chave de acesso corrompida — a Spedy default pra nNF=0 em
     * product-invoices quando `series`/`number` (raiz, opcionais no schema)
     * não são mandados. Confirmado empiricamente no sandbox: mandando
     * `series`/`number` explícitos, a nota sai `enqueued` sem esse erro.
     * NFS-e não precisa disso — a Spedy atribui o próprio número nesse
     * recurso. Usa `numeroAlocado`/`serieNf`, já reservados por
     * IniciarEmissaoNotaService (mesma numeração interna da Configuracao).
     *
     * Depois desses dois fixes, sobrou rejeição de conteúdo (SEFAZ 696):
     * "Operação com não contribuinte deve indicar operação com consumidor
     * final". `isFinalCustomer` estava hardcoded `false` (comentário antigo:
     * "NF-e é sempre B2B"), mas `clientes` não tem coluna de Inscrição
     * Estadual nenhuma — o destinatário nunca é mandado como contribuinte
     * pra Spedy, então a SEFAZ trata como não-contribuinte SEMPRE e exige
     * `isFinalCustomer: true` (== NFC-e, que já mandava `true`). Corrigido
     * pra `true` — confirmado no sandbox: sem os 3 erros de schema (fixes
     * acima) e com isso, a NF-e passa da validação estrutural E da regra
     * 696 (autorização em si depende do resto do cadastro fiscal do
     * item/CFOP, não testado item a item nesta rodada).
     */
    public function montarPayloadNfe(NotaFiscalData $n): array
    {
        $docTomador = preg_replace('/\D/', '', $n->tomador['cpf_cnpj'] ?? '') ?: '';
        $crt        = \App\Services\Fiscal\CrtResolver::resolver($n->regimeTributario);
        $campoIcms  = $crt === 1 ? 'csosn' : 'cst'; // CRT=1 (Simples Nacional) usa CSOSN, senão CST
        $valorTotal = round(array_sum(array_map(
            fn ($item) => (float) $item['quantidade'] * (float) $item['valor_unitario'],
            $n->itens
        )), 2);

        // Corrigido 2026-09-23 depois de confirmar o schema REAL da Spedy
        // (openapi/v1.json, `SefazInvoiceReceiverDto` e
        // `CreateProductInvoiceDto` — não adivinhado): existe sim um campo
        // pra IE do destinatário, `receiver.stateTaxNumber` ("Inscrição
        // estadual"). O que NÃO existe é um `indIEDest` explícito — o único
        // indicador do schema é `isFinalCustomer` (documentado como
        // "Consumidor Final [indFinal]"), que é um campo fiscal DIFERENTE
        // de indIEDest (indFinal = operação com consumidor final; indIEDest
        // = situação da IE do destinatário — dois indicadores distintos no
        // layout real da NF-e). A Spedy provavelmente deriva indIEDest
        // internamente a partir de stateTaxNumber estar preenchido ou não.
        // Uma venda B2B pra um destinatário contribuinte não é, em regra,
        // "operação com consumidor final" — por isso isFinalCustomer=false
        // quando há IE real ou isenção (indicador 1/2), true só pra pessoa
        // física/não contribuinte (indicador 9, mesmo comportamento de
        // antes). Ver IndicadorIeDestinatarioResolver (NF-e #13, cStat=232)
        // pra a mesma correção nos outros dois motores.
        //
        // Corrigido 2026-09-23 (continuação, auditoria fiscal completa): o
        // parágrafo acima já EXPLICAVA que indFinal e indIEDest são dois
        // indicadores distintos, mas o código seguinte conflava os dois
        // mesmo assim (isFinalCustomer derivado de indicador_ie). Pra uma
        // oficina mecânica, a venda é sempre pro consumidor final de
        // verdade do serviço/peça — mesmo quando esse cliente é uma PJ com
        // IE (frota de empresa, por exemplo), ele não está comprando pra
        // revenda. isFinalCustomer=false só faria sentido numa venda B2B
        // pra revenda, que este negócio não faz. Mesma regra já usada no
        // motor NFePHP (MotorNfe::montarNfe(), `indFinal => 1` fixo) —
        // agora consistente entre os dois motores. `stateTaxNumber`
        // continua condicionado a indicador_ie (isso sim está certo: é a
        // IE em si, não o indFinal).
        $indicadorIe = $n->tomador['indicador_ie'] ?? 9;

        return array_filter([
            // integrationId: mesmo fix de reconciliação de montarPayloadNfse()
            // — ver comentário lá.
            'integrationId'   => $this->integrationIdDe($n->referenciaExterna),
            'additionalInformation' => $n->informacoesComplementares, // NF-e/NFC-e: infCpl (doc Spedy)
            // series/number: ver docblock acima — omitidos (null) quando
            // NotaFiscalData não os carrega (ex.: chamada direta em teste).
            'series'          => $n->serieNf,
            'number'          => $n->numeroAlocado !== null ? (int) $n->numeroAlocado : null,
            'isFinalCustomer' => true,
            // Operação presencial (balcão da oficina) — mesma regra já
            // hardcoded em MotorNfe::montarNfe() (`indPres => 1`), agora
            // também mandada pra Spedy (campo `presenceType`, confirmado
            // ao vivo em openapi/v1.json: enum inclui `presence` =
            // "Operação presencial [indPres]").
            'presenceType'    => 'presence',
            'operationNature' => $n->naturezaOperacao,
            'receiver' => array_filter([
                'name'             => $n->tomador['nome'],
                'federalTaxNumber' => $docTomador,
                'stateTaxNumber'   => $indicadorIe === 1 ? ($n->tomador['inscricao_estadual'] ?? null) : null,
                // Obrigatório para NF-e — ver enderecoDestinatario().
                'address'          => $this->enderecoDestinatario($n->tomador),
            ]),
            'items' => array_map(fn (int $i, array $item) => [
                'code'        => $item['sku'] ?? $item['produto_id'],
                'description' => $item['descricao'],
                'ncm'         => $item['ncm'],
                // Obrigatório quando o CST/CSOSN indica ST — ver comentário
                // em montarPayloadNfce(). Vem de produtos.cest.
                'cest'        => $item['cest'] ?? null,
                'cfop'        => (int) $item['cfop'],
                'unit'        => $item['unidade'] ?? 'UN',
                'quantity'    => (float) $item['quantidade'],
                'unitAmount'  => (float) $item['valor_unitario'],
                'totalAmount' => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                // uTrib/qTrib/vUnTrib — obrigatórios (ver doc acima). Sem
                // unidade de conversão nesta v1, tributável = comercial.
                'unitTax'       => $item['unidade'] ?? 'UN',
                'quantityTax'   => (float) $item['quantidade'],
                'unitTaxAmount' => (float) $item['valor_unitario'],
                'makeupTotal'   => true,
                'taxes' => [
                    'icms' => [
                        'origin'   => (int) $item['origem'],
                        $campoIcms => (int) $item['cst_csosn'],
                    ],
                    // PIS/COFINS: rejeição real "745: NF-e sem grupo do PIS"
                    // (homologação 2026-09-10) — apesar da doc da Spedy marcar
                    // `taxes.pis`/`taxes.cofins` como opcionais, a SEFAZ exige
                    // o grupo em toda NF-e (XSD v4.00, achado já confirmado
                    // pra NFePHP em MotorNfe::montarNfe() contra o XSD real).
                    // CST 49 ("Outras Operações") com base/alíquota/valor
                    // zerados é o padrão pra Simples Nacional (CRT=1): PIS/
                    // COFINS é pago via DAS unificado, não calculado por
                    // operação. Mesmo valor pra CRT=3 nesta v1 — nenhuma
                    // oficina real deste sistema é Regime Normal ainda.
                    'pis'    => ['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0],
                    'cofins' => ['cst' => 49, 'baseTax' => 0, 'rate' => 0, 'amount' => 0],
                ],
            ], array_keys($n->itens), $n->itens),
            'payments' => [[
                'method' => $this->mapFormaPagamento($n->formaPagamento),
                'amount' => $valorTotal,
            ]],
        ], fn ($v) => $v !== null);
    }

    private function mapFormaPagamento(string $forma): string
    {
        // Enum completo confirmado na doc (payments[].method).
        return match ($forma) {
            'Dinheiro'          => 'money',
            'Cartão de Crédito' => 'creditCard',
            'Cartão de Débito'  => 'debitCard',
            'PIX'               => 'pix',
            'Cheque'            => 'check',
            'Boleto'            => 'billetBanking',
            default             => 'other',
        };
    }

    /**
     * Emissão no modo AUTOMATICO_PROVEDOR: POST /v1/orders SEM nenhum campo
     * fiscal — a Spedy resolve a tributação a partir da config da empresa no
     * painel dela. Contrato confirmado no spike de 2026-09-05.
     */
    private function emitirViaOrders(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/orders", $this->montarPayloadOrder($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro ao criar a venda na Spedy (/orders).'),
                $nota->referenciaExterna,
            );
        }

        $invoice = $resp->json('invoices.0');
        if (!is_array($invoice) || empty($invoice['id'])) {
            return EmissaoResultado::rejeitada(
                'A Spedy criou a venda mas não devolveu nenhuma nota fiscal.',
                $nota->referenciaExterna,
            );
        }

        $status = $this->mapStatus((string) ($invoice['status'] ?? 'enqueued'));
        if ($status === 'REJEITADA') {
            $msg = $this->prefixoDoStatusBruto((string) ($invoice['status'] ?? ''))
                . ($invoice['processingDetail']['message'] ?? 'Rejeitada pela SEFAZ.');
            return EmissaoResultado::rejeitada($msg, (string) $invoice['id']);
        }

        // Assíncrono: número/chave só existem depois de autorizada. A
        // referência externa passa a ser o id da invoice da Spedy — consultar()
        // usa em /product-invoices/{id} pra reconciliar (spike-confirmado).
        return EmissaoResultado::processando((string) $invoice['id']);
    }

    /**
     * Payload do POST /v1/orders — SEM nenhum campo fiscal (NCM/CFOP/CST/ICMS).
     * A Spedy resolve a tributação. `invoiceModel` do produto distingue
     * NF-e/NFC-e/NFS-e.
     */
    private function montarPayloadOrder(NotaFiscalData $n): array
    {
        $invoiceModel = match ($n->modelo) {
            'NFE'   => 'productInvoice',
            'NFCE'  => 'consumerInvoice',
            default => 'serviceInvoice',
        };

        $itens = $n->itens !== []
            ? array_map(fn (array $item) => [
                'description' => $item['descricao'],
                'quantity'    => (float) $item['quantidade'],
                'price'       => (float) $item['valor_unitario'],
                'amount'      => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                'product'     => [
                    'name'         => $item['descricao'],
                    'code'         => $item['sku'] ?? $item['produto_id'],
                    'price'        => (float) $item['valor_unitario'],
                    'invoiceModel' => $invoiceModel,
                ],
            ], $n->itens)
            : [[
                // NFS-e não tem itens — vira 1 item sintético do serviço inteiro.
                'description' => $n->descricao,
                'quantity'    => 1.0,
                'price'       => $n->valorServicos,
                'amount'      => $n->valorServicos,
                'product'     => [
                    'name'         => $n->descricao,
                    'code'         => 'servico',
                    'price'        => $n->valorServicos,
                    'invoiceModel' => 'serviceInvoice',
                ],
            ]];

        $total = round(array_sum(array_map(fn ($i) => $i['amount'], $itens)), 2);

        return [
            'transactionId' => $n->referenciaExterna,
            'amount'        => $total,
            'date'          => now()->toIso8601String(),
            'customer'      => [
                'name'             => $n->tomador['nome'] ?? '-',
                'federalTaxNumber' => (preg_replace('/\D/', '', $n->tomador['cpf_cnpj'] ?? '') ?: null),
                'email'            => $n->tomador['email'] ?? null,
                'address'          => [
                    'street'     => $n->tomador['logradouro'] ?? null,
                    'number'     => $n->tomador['numero'] ?? 'S/N',
                    'district'   => $n->tomador['bairro'] ?? null,
                    'postalCode' => (preg_replace('/\D/', '', $n->tomador['cep'] ?? '') ?: null),
                    'city'       => ['code' => (($n->tomador['codigo_ibge'] ?? '') ?: null)],
                ],
            ],
            'items' => $itens,
        ];
    }

    private function emitirNfe(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/product-invoices", $this->montarPayloadNfe($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro na emissão de NF-e (Spedy).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna, 'product-invoices');
    }

    private function emitirNfce(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/consumer-invoices", $this->montarPayloadNfce($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $this->mensagemErroDe($resp, 'Erro na emissão de NFC-e (Spedy).'),
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoNfceDe($resp->json(), $nota->referenciaExterna, 'consumer-invoices');
    }

    private function resultadoNfceDe(array $json, ?string $ref, string $recurso = 'consumer-invoices'): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'enqueued'));

        if ($status === 'REJEITADA') {
            $msg = $this->prefixoDoStatusBruto((string) ($json['status'] ?? ''))
                . ($json['processingDetail']['message'] ?? ($json['message'] ?? 'Rejeitada pela SEFAZ.'));
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
            protocolo: $json['authorization']['protocol'] ?? null,
            numero: isset($json['number']) ? (string) $json['number'] : null,
            xml: $this->xmlAutorizadoDe($recurso, $json['id'] ?? null),
            // pdfUrl: campo morto — NotaFiscalController::pdf() sempre gera o
            // PDF localmente via DomPDF (NotaFiscalDocumentoService), nunca lê
            // esta coluna. Mantido como leitura simples (sem custo de rede
            // extra) só por completude — não vale criar um pdfAutorizadoDe()
            // dedicado (como o de XML) pra popular um dado que nada consome.
            pdfUrl: $json['pdfUrl'] ?? null,
            ref: $ref,
            qrCodeUrl: $json['qrCodeUrl'] ?? ($json['qrcodeUrl'] ?? null),
        );
    }

    /**
     * Bug real achado 2026-09-23 (auditoria fiscal completa): o `InvoiceStatus`
     * real da Spedy (confirmado ao vivo em openapi/v1.json) tem 10 valores —
     * `created, enqueued, received, authorized, inContingent, rejected,
     * canceled, denied, removed, disabled` —, mas este `match` só cobria 5.
     * Os outros 5 caíam no fallback `statusDesconhecido()`, que devolve
     * PROCESSANDO. Pior caso real: `denied` (Denegado — problema no CADASTRO
     * do emitente junto à SEFAZ, juridicamente diferente de `rejected`, que é
     * problema no documento) ficava preso como "ainda processando" pra
     * sempre, sem nunca virar uma falha real e acionável. `'processing'` foi
     * removido do match: não é um valor real de `InvoiceStatus` (confirmado
     * ao vivo), parece ter sido copiado por engano do enum não-relacionado
     * `InvoiceProcessingStatus`.
     *
     * Vocabulário desta app só tem 4 status (AUTORIZADA/PROCESSANDO/
     * REJEITADA/CANCELADA — ver EmissaoResultado) — os 10 valores da Spedy
     * precisam encaixar em algum destes 4, nenhum mapeia perfeito:
     * - `created`/`received`: pré-emissão / em trânsito — não são finais,
     *   PROCESSANDO é o correto.
     * - `inContingent`: contingência é estado transitório por definição
     *   (documento ainda será transmitido de verdade) — PROCESSANDO.
     * - `denied`/`removed`/`disabled`: os 3 são desfechos TERMINAIS
     *   negativos (nunca vão virar autorizados) mas nenhum é a mesma coisa
     *   que `rejected` — mapeados pra REJEITADA (a alternativa seria
     *   PROCESSANDO pra sempre, repetindo o mesmo bug do `denied`), com a
     *   mensagem prefixada em resultadoDe()/resultadoNfceDe()/
     *   emitirViaOrders() (ver prefixoDoStatusBruto()) pra não ficarem
     *   indistinguíveis de uma rejeição comum pra quem for debugar depois.
     */
    public function mapStatus(string $spedyStatus): string
    {
        return match ($spedyStatus) {
            'authorized' => 'AUTORIZADA',
            'canceled' => 'CANCELADA',
            'rejected', 'denied', 'removed', 'disabled' => 'REJEITADA',
            'created', 'enqueued', 'received', 'inContingent' => 'PROCESSANDO',
            default => $this->statusDesconhecido($spedyStatus),
        };
    }

    /**
     * `denied` (Denegado), `removed` (Removido) e `disabled` (Inutilizado)
     * mapeiam todos pra REJEITADA em mapStatus() (vocabulário de 4 status
     * desta app não tem uma categoria própria pra cada um), mas são
     * juridicamente diferentes entre si e de um `rejected` comum — sem essa
     * distinção na mensagem, fica impossível saber depois qual dos quatro
     * realmente aconteceu só olhando `notas_fiscais.mensagem_erro`.
     */
    private function prefixoDoStatusBruto(string $spedyStatus): string
    {
        return match ($spedyStatus) {
            'denied'   => '[Denegado] ',
            'removed'  => '[Removido pela Spedy antes da emissão] ',
            'disabled' => '[Inutilizado] ',
            default    => '',
        };
    }

    private function statusDesconhecido(string $status): string
    {
        \Illuminate\Support\Facades\Log::warning(
            'Spedy: status desconhecido recebido, tratando como PROCESSANDO.',
            ['status' => $status],
        );
        return 'PROCESSANDO';
    }

    /**
     * Bug real achado 2026-09-15 (auditoria campo-a-campo contra
     * docs.spedy.com.br/api-reference/empresas/criar-empresa.md): o enum
     * `TaxRegime` da Spedy só aceita `simplesNacional`,
     * `simplesNacionalExcessoSublimite`, `regimeNormal` e
     * `simplesNacionalMEI` — `lucroPresumido`/`lucroReal` NÃO EXISTEM como
     * valores. `POST /v1/companies` rejeitava com erro de enum inválido pra
     * toda oficina fora do Simples Nacional, bloqueando o cadastro fiscal
     * inteiro antes de qualquer emissão. Lucro Presumido e Lucro Real caem
     * juntos em `regimeNormal` ("Regime Normal (Lucro Presumido ou Lucro
     * Real)", conforme a própria doc).
     */
    private function mapRegime(string $regime): string
    {
        $r = strtolower($regime);
        return match (true) {
            str_contains($r, 'simples') => 'simplesNacional',
            str_contains($r, 'presumido'), str_contains($r, 'real') => 'regimeNormal',
            default => 'simplesNacional',
        };
    }

    private function resultadoDe(array $json, ?string $ref, string $recurso = 'service-invoices'): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'enqueued'));

        if ($status === 'REJEITADA') {
            $msg = $this->prefixoDoStatusBruto((string) ($json['status'] ?? ''))
                . ($json['processingDetail']['message'] ?? ($json['message'] ?? 'Rejeitada pela SEFAZ/Prefeitura.'));
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
            // 2026-09-15: confirmado contra docs.spedy.com.br que a resposta
            // (NF-e/NFC-e/NFS-e) traz `authorization.protocol` — substituindo
            // o `null` fixo de 2026-08-03 (na época sem confirmação; "number"
            // nunca foi o protocolo, isso continua certo, só a fonte real do
            // protocolo era outra chave, não ausência de protocolo nenhum).
            protocolo: $json['authorization']['protocol'] ?? null,
            numero: isset($json['number']) ? (string) $json['number'] : null,
            xml: $this->xmlAutorizadoDe($recurso, $json['id'] ?? null),
            pdfUrl: $json['pdfUrl'] ?? null, // campo morto — ver resultadoNfceDe()
            ref: $ref,
        );
    }

    /**
     * Bug real de produção (2026-09-14, achado ao verificar "botão de baixar
     * XML funciona pra todos os motores?"): `resultadoDe()`/`resultadoNfceDe()`
     * liam `$json['xml'] ?? null` — mas a doc oficial da Spedy
     * (docs.spedy.com.br) confirma que o corpo de emissão/consulta NUNCA
     * traz o XML inline; é preciso baixar via endpoint dedicado
     * `GET /{recurso}/{id}/xml` (só funciona depois de autorizada). Isso
     * significava que TODA nota autorizada via Spedy tinha `xml_retorno`
     * sempre vazio — confirmado no banco de produção (zero notas com
     * `xml_retorno` preenchido antes deste fix). Falha ao baixar não pode
     * derrubar a autorização em si (a nota já foi autorizada de verdade,
     * só o XML fica ausente) — por isso retorna null em vez de lançar.
     */
    private function xmlAutorizadoDe(string $recurso, mixed $id): ?string
    {
        if (empty($id)) {
            return null;
        }

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/{$recurso}/{$id}/xml");

        if ($resp->failed()) {
            \Illuminate\Support\Facades\Log::warning('Spedy: falha ao baixar XML autorizado.', [
                'recurso' => $recurso, 'id' => $id, 'status' => $resp->status(),
            ]);
            return null;
        }

        return $resp->body();
    }

    public function consultarNotaRecebida(string $chaveAcesso): ConsultaNotaTerceiroResultado
    {
        // NUNCA cair no masterKey aqui: a master key é da plataforma, não é
        // escopada a uma empresa — usá-la nos endpoints de notas recebidas
        // devolveria notas de OUTROS tenants. Sem emissor registrado, não
        // existe consulta legítima a fazer.
        if ($this->emissorToken === null) {
            return ConsultaNotaTerceiroResultado::erro('Esta oficina ainda não está registrada na Spedy.');
        }

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken])
            ->get("{$this->baseUrl}/inbound-product-invoices", ['accessKey' => $chaveAcesso]);

        if ($resp->failed()) {
            \Illuminate\Support\Facades\Log::warning(
                'Spedy: falha ao consultar nota recebida.',
                ['chave_acesso' => $chaveAcesso, 'status' => $resp->status(), 'corpo' => $resp->body()],
            );
            return ConsultaNotaTerceiroResultado::erro($this->mensagemErroDe($resp, 'Erro ao consultar nota na Spedy.'));
        }

        $itens = $resp->json('items') ?? [];
        $nota  = $itens[0] ?? null;
        if ($nota === null) {
            return ConsultaNotaTerceiroResultado::naoEncontrada();
        }

        if (($nota['isComplete'] ?? false) !== true) {
            $manifestoResp = Http::withHeaders(['X-Api-Key' => $this->emissorToken])
                ->post("{$this->baseUrl}/inbound-product-invoices/{$nota['id']}/manifest", ['status' => 'acknowledged']);

            if ($manifestoResp->failed()) {
                \Illuminate\Support\Facades\Log::warning(
                    'Spedy: falha ao registrar ciência da operação (manifesto).',
                    [
                        'chave_acesso' => $chaveAcesso,
                        'nota_id'      => $nota['id'] ?? null,
                        'status'       => $manifestoResp->status(),
                        'corpo'        => $manifestoResp->body(),
                    ],
                );
                return ConsultaNotaTerceiroResultado::erro('Falha ao registrar ciência da operação na Spedy.');
            }

            return ConsultaNotaTerceiroResultado::aguardandoManifestacao();
        }

        $xmlResp = Http::withHeaders(['X-Api-Key' => $this->emissorToken])
            ->get("{$this->baseUrl}/inbound-product-invoices/{$nota['id']}/xml");

        if ($xmlResp->failed()) {
            \Illuminate\Support\Facades\Log::warning(
                'Spedy: falha ao baixar o XML da nota recebida.',
                ['chave_acesso' => $chaveAcesso, 'nota_id' => $nota['id'] ?? null, 'status' => $xmlResp->status()],
            );
            return ConsultaNotaTerceiroResultado::erro('Erro ao baixar o XML da nota na Spedy.');
        }

        return ConsultaNotaTerceiroResultado::completa((new NotaEntradaXmlParser())->parse($xmlResp->body()));
    }

    public function listarNotasRecebidas(string $cnpjOficina, ?\DateTimeInterface $desde = null): array
    {
        // Mesma regra de consultarNotaRecebida(): sem emissor registrado não
        // há chamada legítima — a master key traria notas de outros tenants.
        if ($this->emissorToken === null) {
            return [];
        }

        $query = [];
        if ($desde !== null) {
            $query['initialDate'] = $desde->format('Y-m-d');
        }

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken])
            ->get("{$this->baseUrl}/inbound-product-invoices", $query);

        if ($resp->failed()) {
            $mensagem = $this->mensagemErroDe($resp, 'Erro ao listar notas recebidas na Spedy.');
            \Illuminate\Support\Facades\Log::warning(
                'Spedy: falha ao listar notas recebidas.',
                ['url' => "{$this->baseUrl}/inbound-product-invoices", 'status' => $resp->status(), 'corpo' => $resp->body()],
            );
            throw new \RuntimeException($mensagem);
        }

        return array_map(fn (array $item) => new ConsultaNotaTerceiroResumo(
            chaveAcesso: (string) ($item['accessKey'] ?? ''),
            fornecedorNome: $item['issuer']['name'] ?? null,
            fornecedorCnpj: $item['issuer']['federalTaxNumber'] ?? null,
            dataEmissao: isset($item['issuedOn']) ? substr((string) $item['issuedOn'], 0, 10) : null,
            valorTotal: (float) ($item['amount'] ?? 0),
            completa: ($item['isComplete'] ?? false) === true,
        ), $resp->json('items') ?? []);
    }
}

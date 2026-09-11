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
                $resp->json('message') ?? 'Erro na emissão (Spedy).',
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado
    {
        $recurso = $this->recursoPorModelo($modelo);

        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->get("{$this->baseUrl}/{$recurso}/{$referencia}");

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('message') ?? 'Erro ao consultar (Spedy).', $referencia);
        }

        return $modelo === 'NFCE'
            ? $this->resultadoNfceDe($resp->json(), $referencia)
            : $this->resultadoDe($resp->json(), $referencia);
    }

    public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
    {
        $recurso = $this->recursoPorModelo($modelo);

        // Campo confirmado como `reason` na doc (docs.spedy.com.br/api-reference/
        // {nfs-e,nfc-e,nf-e}/cancelar-*.md) — `justification` era um chute anterior,
        // nunca validado em sandbox real, corrigido nesta sessão.
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->delete("{$this->baseUrl}/{$recurso}/{$referencia}", [
                'reason' => $motivo,
            ]);

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada($resp->json('message') ?? 'Erro ao cancelar (Spedy).', $referencia);
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
            'operationNature'     => $n->naturezaOperacao,
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

    // Payload inferido a partir do padrão camelCase já usado por montarPayloadNfse()/
    // montarPayloadEmpresa() — a doc pública da Spedy só confirma `isFinalCustomer`
    // como campo obrigatório. Precisa ser validado contra sandbox real antes de
    // confiar em produção (mesma ressalva já registrada pra NF-e-Spedy no projeto).
    public function montarPayloadNfce(NotaFiscalData $n): array
    {
        $docTomador     = preg_replace('/\D/', '', $n->tomador['cpf_cnpj']) ?? '';
        $ehPessoaFisica = strlen($docTomador) <= 11;
        $valorTotal     = round(array_sum(array_map(
            fn ($item) => (float) $item['quantidade'] * (float) $item['valor_unitario'],
            $n->itens
        )), 2);

        return array_filter([
            // series/number: mesmo achado de montarPayloadNfe() — não
            // confirmado empiricamente pra consumer-invoices especificamente,
            // mas o schema raiz é o mesmo SefazInvoiceItemDto-family da NF-e,
            // então mandamos por precaução (omitidos quando não carregados).
            'series'          => $n->serieNf,
            'number'          => $n->numeroAlocado !== null ? (int) $n->numeroAlocado : null,
            'isFinalCustomer' => true,
            'operationNature' => $n->naturezaOperacao,
            'receiver' => array_merge(
                ['name' => $n->tomador['nome']],
                [$ehPessoaFisica ? 'individualTaxNumber' : 'federalTaxNumber' => $docTomador],
                // NFC-e a consumidor final costuma dispensar endereço (venda de
                // balcão sem cadastro). Só manda o bloco quando o cliente tem
                // um logradouro cadastrado — senão a Spedy pode recusar um
                // address todo vazio numa nota que passaria sem ele.
                empty($n->tomador['logradouro'])
                    ? []
                    : ['address' => $this->enderecoDestinatario($n->tomador)],
            ),
            'items' => array_map(fn (int $i, array $item) => [
                'itemNumber'       => $i + 1,
                'productCode'      => $item['sku'] ?? $item['produto_id'],
                'description'      => $item['descricao'],
                'ncm'              => $item['ncm'],
                // Obrigatório quando icmsTaxSituation indica ST (rejeição
                // real "806: operação com ICMS-ST sem CEST", homologação
                // 2026-09-10). Vem de produtos.cest via NfeService.
                'cest'             => $item['cest'] ?? null,
                'cfop'             => $item['cfop'],
                'commercialUnit'   => $item['unidade'] ?? 'UN',
                'quantity'         => (float) $item['quantidade'],
                'unitValue'        => (float) $item['valor_unitario'],
                'grossValue'       => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
                // Campos tributáveis (uTrib/qTrib/vUnTrib) — ver comentário em
                // montarPayloadNfe(). Sem unidade de conversão nesta v1, o
                // tributável é sempre igual ao comercial.
                'unitTax'          => $item['unidade'] ?? 'UN',
                'quantityTax'      => (float) $item['quantidade'],
                'unitTaxAmount'    => (float) $item['valor_unitario'],
                'makeupTotal'      => true,
                'icmsOrigin'       => (int) $item['origem'],
                'icmsTaxSituation' => $item['cst_csosn'],
            ], array_keys($n->itens), $n->itens),
            'payments' => [[
                'method' => 'cash',
                'value'  => $valorTotal,
            ]],
        ], fn ($v) => $v !== null);
    }

    /**
     * Schema confirmado contra docs.spedy.com.br/api-reference/nf-e/criar-nf-e.md
     * (2026-09-04): POST /v1/product-invoices. Ao contrário de montarPayloadNfce()
     * (que usa `cfop` string e um único campo `icmsTaxSituation`), este endpoint
     * usa `cfop` **integer** e separa `cst`/`csosn` em campos distintos dentro de
     * `taxes.icms` — dai o CrtResolver aqui.
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

        return array_filter([
            // series/number: ver docblock acima — omitidos (null) quando
            // NotaFiscalData não os carrega (ex.: chamada direta em teste).
            'series'          => $n->serieNf,
            'number'          => $n->numeroAlocado !== null ? (int) $n->numeroAlocado : null,
            // SEFAZ 696 — ver docblock acima: `clientes` não tem IE, então o
            // destinatário é sempre não-contribuinte pra Spedy, e isso exige
            // isFinalCustomer=true (era `false` até 2026-09-10, rejeitava
            // toda NF-e real). Igual à NFC-e, que já mandava `true`.
            'isFinalCustomer' => true,
            'operationNature' => $n->naturezaOperacao,
            'receiver' => [
                'name'             => $n->tomador['nome'],
                'federalTaxNumber' => $docTomador,
                // Obrigatório para NF-e — ver enderecoDestinatario().
                'address'          => $this->enderecoDestinatario($n->tomador),
            ],
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
                $resp->json('message') ?? 'Erro ao criar a venda na Spedy (/orders).',
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
            return EmissaoResultado::rejeitada(
                $invoice['processingDetail']['message'] ?? 'Rejeitada pela SEFAZ.',
                (string) $invoice['id'],
            );
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
                $resp->json('message') ?? 'Erro na emissão de NF-e (Spedy).',
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoDe($resp->json(), $nota->referenciaExterna);
    }

    private function emitirNfce(NotaFiscalData $nota): EmissaoResultado
    {
        $resp = Http::withHeaders(['X-Api-Key' => $this->emissorToken ?? $this->masterKey])
            ->post("{$this->baseUrl}/consumer-invoices", $this->montarPayloadNfce($nota));

        if ($resp->failed()) {
            return EmissaoResultado::rejeitada(
                $resp->json('message') ?? 'Erro na emissão de NFC-e (Spedy).',
                $nota->referenciaExterna,
            );
        }

        return $this->resultadoNfceDe($resp->json(), $nota->referenciaExterna);
    }

    private function resultadoNfceDe(array $json, ?string $ref): EmissaoResultado
    {
        $status = $this->mapStatus((string) ($json['status'] ?? 'enqueued'));

        if ($status === 'REJEITADA') {
            $msg = $json['processingDetail']['message'] ?? ($json['message'] ?? 'Rejeitada pela SEFAZ.');
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
            protocolo: null,
            numero: isset($json['number']) ? (string) $json['number'] : null,
            xml: $json['xml'] ?? null,
            pdfUrl: $json['pdfUrl'] ?? null,
            ref: $ref,
            qrCodeUrl: $json['qrCodeUrl'] ?? ($json['qrcodeUrl'] ?? null),
        );
    }

    public function mapStatus(string $spedyStatus): string
    {
        return match ($spedyStatus) {
            'authorized'             => 'AUTORIZADA',
            'rejected'               => 'REJEITADA',
            'canceled'               => 'CANCELADA',
            'enqueued', 'processing' => 'PROCESSANDO',
            default                  => $this->statusDesconhecido($spedyStatus),
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
            // 2026-08-03: não reusa "number" como protocolo (defeito #4) — a doc
            // de NFS-e da Spedy não confirma um campo de protocolo distinto de
            // "number". Sem confirmação, documentamos como limitação do
            // provedor em vez de inventar um valor (ver spec da Etapa B).
            protocolo: null,
            numero: isset($json['number']) ? (string) $json['number'] : null,
            xml: $json['xml'] ?? null,
            pdfUrl: $json['pdfUrl'] ?? null,
            ref: $ref,
        );
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
            return ConsultaNotaTerceiroResultado::erro($resp->json('message') ?? 'Erro ao consultar nota na Spedy.');
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
            $mensagem = (string) ($resp->json('message') ?? 'Erro ao listar notas recebidas na Spedy.');
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

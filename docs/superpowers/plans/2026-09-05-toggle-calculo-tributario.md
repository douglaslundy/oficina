# Toggle `calculo_tributario_modo` (MANUAL vs AUTOMATICO_PROVEDOR) — Design + Plano

**Goal:** hoje o sistema calcula CFOP/CST/ICMS/ISS de cada item
(`CfopSaidaResolver`/`TributacaoIcmsSaidaResolver`/etc.) e manda tudo
pronto pro provedor. Adicionar um toggle por oficina que, quando ligado,
deixa a **Spedy** calcular a tributação sozinha via o fluxo `POST /v1/orders`
(nenhum campo fiscal no payload).

**Grounding (spike REAL contra o sandbox Spedy, 2026-09-05):** mandei
`POST /v1/orders` sem NCM/CFOP/CST/ICMS nenhum — a nota passou por TODA a
validação fiscal e só foi rejeitada por falta do certificado A1
(`SPD003`), o mesmo bloqueio que trava qualquer emissão. Ou seja: o
cálculo automático **funciona de verdade**. Contrato confirmado:
- Obrigatórios: `amount`, `date`, `items[]` (cada item: `quantity`,
  `price`, `amount`, `product{name, code, price, invoiceModel}`).
- `invoiceModel`: `productInvoice` (NF-e) | `consumerInvoice` (NFC-e) |
  `serviceInvoice` (NFS-e).
- `customer.address` completo é obrigatório (`street`, `number`,
  `district`, `postalCode`, `city.code`).
- Resposta: `{invoices: [{id, status: "enqueued", model, processingDetail}]}`
  — assíncrono. `GET /v1/product-invoices/{invoices[0].id}` funciona pra
  reconciliar (spike-confirmado).
- `mapStatus()` do `SpedyProvider` já mapeia `enqueued`/`processing` →
  `PROCESSANDO`.

## Escopo e constraints

- **v1 = Spedy only.** Focus "Automations" não tem contrato de API
  confirmado — no modo AUTOMATICO_PROVEDOR a Focus retorna
  `EmissaoResultado::rejeitada('Cálculo automático de tributação ainda
  não é suportado pela Focus — use o modo MANUAL.')`.
- Default `MANUAL` — comportamento atual, zero mudança pra quem não ligar.
- Opt-in por oficina, com aviso na UI (exige certificado A1 + config de
  regime/grupos no painel web da Spedy).
- Não confirmado contra catálogo variado real: se a "config da empresa"
  na Spedy usa um NCM/CFOP padrão único (a própria doc chama `/v1/orders`
  de fluxo pra "cenários simples"). Registrar isso como limitação
  conhecida na UI e no PROGRESSO.md.
- `declare(strict_types=1)`, TDD.

---

### Task 1: Migração + model

- Create: `backend/database/migrations/2026_09_05_000002_add_calculo_tributario_modo_to_configuracoes.php`

```php
Schema::table('configuracoes', function (Blueprint $table) {
    $table->string('calculo_tributario_modo', 20)->default('MANUAL'); // MANUAL | AUTOMATICO_PROVEDOR
});
```

- Modify `backend/app/Models/Configuracao.php`: adicionar `'calculo_tributario_modo'` ao `$fillable`.
- Commit: `feat(fiscal): coluna calculo_tributario_modo em configuracoes`

---

### Task 2: Threading do modo até o provider (`NotaFiscalData` + `NfeService`)

- Modify `backend/app/Services/Fiscal/Data/NotaFiscalData.php`: novo param no fim do construtor:

```php
// Configuracao.calculo_tributario_modo — 'MANUAL' (padrão, o sistema
// calcula tudo) | 'AUTOMATICO_PROVEDOR' (Spedy calcula via /v1/orders).
// Populado por NfeService::montarNotaData() pra TODOS os modelos.
public readonly string $calculoTributarioModo = 'MANUAL',
```

- Modify `backend/app/Services/NfeService.php` (`montarNotaData()`, no `return new NotaFiscalData(`): acrescentar
  `calculoTributarioModo: $config?->calculo_tributario_modo ?? 'MANUAL',`

- Test: `backend/tests/Unit/Fiscal/NfeServiceMontagemTest.php` (arquivo já existe) — 1 teste novo
  confirmando que o campo é populado a partir do `Configuracao`.
- Commit: `feat(fiscal): threading do calculo_tributario_modo ate o NotaFiscalData`

---

### Task 3: `SpedyProvider::emitirViaOrders()`

- Modify `backend/app/Services/Fiscal/Providers/SpedyProvider.php`
- Test: `backend/tests/Unit/Fiscal/SpedyProviderTest.php` (extender)

Em `emitir()`, no TOPO do método (antes de qualquer branch de modelo):

```php
public function emitir(NotaFiscalData $nota): EmissaoResultado
{
    if ($nota->calculoTributarioModo === 'AUTOMATICO_PROVEDOR') {
        return $this->emitirViaOrders($nota);
    }
    // ... resto igual (emitirNfe / emitirNfce / service-invoices)
}
```

Novos métodos:

```php
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
 * A Spedy resolve a tributação a partir da config da empresa no painel
 * dela. Contrato confirmado no spike de 2026-09-05.
 */
private function montarPayloadOrder(NotaFiscalData $n): array
{
    $invoiceModel = match ($n->modelo) {
        'NFE'  => 'productInvoice',
        'NFCE' => 'consumerInvoice',
        default => 'serviceInvoice',
    };

    $itens = $n->itens !== []
        ? array_map(fn (array $item) => [
            'description' => $item['descricao'],
            'quantity'   => (float) $item['quantidade'],
            'price'      => (float) $item['valor_unitario'],
            'amount'     => round((float) $item['quantidade'] * (float) $item['valor_unitario'], 2),
            'product'    => [
                'name'         => $item['descricao'],
                'code'         => $item['sku'] ?? $item['produto_id'],
                'price'        => (float) $item['valor_unitario'],
                'invoiceModel' => $invoiceModel,
            ],
        ], $n->itens)
        // NFS-e não tem itens — vira 1 item sintético do serviço inteiro.
        : [[
            'description' => $n->descricao,
            'quantity'   => 1.0,
            'price'      => $n->valorServicos,
            'amount'     => $n->valorServicos,
            'product'    => [
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
            'federalTaxNumber' => preg_replace('/\D/', '', $n->tomador['cpf_cnpj'] ?? '') ?: null,
            'email'            => $n->tomador['email'] ?? null,
            'address'          => [
                'street'         => $n->tomador['logradouro'] ?? null,
                'number'         => $n->tomador['numero'] ?? 'S/N',
                'district'       => $n->tomador['bairro'] ?? null,
                'postalCode'     => preg_replace('/\D/', '', $n->tomador['cep'] ?? '') ?: null,
                'city'           => ['code' => $n->tomador['codigo_ibge'] ?: null],
            ],
        ],
        'items' => $itens,
    ];
}
```

Testes (Http::fake), acrescentar a `SpedyProviderTest`:
- `emitir()` com `calculoTributarioModo: 'AUTOMATICO_PROVEDOR'` faz POST em `/orders` (não em `/product-invoices`),
  o payload NÃO contém `ncm`/`cfop`/`taxes`, e a resposta `{invoices:[{id, status:"enqueued"}]}` vira `PROCESSANDO`
  com `referenciaExterna` = id da invoice.
- resposta `/orders` com `invoices:[{id, status:"rejected", processingDetail:{message:"..."}}]` vira `REJEITADA` com a mensagem.
- `/orders` retornando `failed()` (4xx) vira `REJEITADA` com a `message`.
- Modo `MANUAL` (default) continua indo pra `/product-invoices` (teste de não-regressão do caminho atual).

Commit: `feat(fiscal): SpedyProvider emite via /v1/orders quando modo AUTOMATICO_PROVEDOR`

---

### Task 4: `FocusNfeProvider` recusa o modo automático (v1)

- Modify `backend/app/Services/Fiscal/Providers/FocusNfeProvider.php`
- Test: `backend/tests/Unit/Fiscal/FocusNfeProviderTest.php`

Em `emitir()`, no topo:

```php
if ($nota->calculoTributarioModo === 'AUTOMATICO_PROVEDOR') {
    return EmissaoResultado::rejeitada(
        'Cálculo automático de tributação ainda não é suportado pela Focus — use o modo MANUAL nas configurações fiscais.',
        $nota->referenciaExterna,
    );
}
```

Teste: `emitir()` com modo AUTOMATICO_PROVEDOR retorna REJEITADA com essa mensagem, sem fazer nenhum HTTP request (`Http::fake(); ... Http::assertNothingSent();`).

Commit: `feat(fiscal): FocusNfeProvider recusa modo automatico na v1 (so Spedy)`

---

### Task 5: Frontend — toggle na tela de Configurações Fiscais

- Modify: `frontend/app/(dashboard)/configuracoes/page.tsx` (ou onde ficam os "Dados da Empresa"/config fiscal —
  procurar pelo campo `regime_tributario` no frontend, o toggle fica perto dele).

Um `<select>` ou toggle com 2 opções:
- **Manual (o sistema calcula)** — default.
- **Automático pelo provedor (Spedy)** — com texto de aviso embaixo:
  "A Spedy calcula CFOP/CST/ICMS/ISS a partir da configuração fiscal da
  sua empresa no painel dela. Exige: certificado A1 enviado à Spedy +
  regime tributário e grupos de tributação configurados no painel web da
  Spedy. Só funciona bem para catálogos fiscais simples. A Focus ainda
  não suporta este modo."

Enviar `calculo_tributario_modo` no PUT de configurações que já existe.
`npx tsc --noEmit` limpo.

Commit: `feat(configuracoes): toggle de modo de calculo tributario`

---

### Task 6: `ConfiguracaoController` aceita o campo + PROGRESSO.md/TAREFAS.md

- Modify o controller de configurações pra validar/persistir `calculo_tributario_modo`
  (`'nullable', 'string', 'in:MANUAL,AUTOMATICO_PROVEDOR'`).
- Teste de feature confirmando o PUT persiste o valor.
- Registrar rodada no PROGRESSO.md, marcar item no TAREFAS.md (sair de "bloqueado" pra "concluído").
- Commit + push.

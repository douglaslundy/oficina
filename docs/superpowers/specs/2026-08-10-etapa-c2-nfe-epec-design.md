# Etapa C2 — NF-e via NFePHP (`sped-nfe`) + contingência EPEC — design

## Contexto

Terceira e última fatia do roteiro do motor fiscal NFePHP, que originalmente
foi especificado como um único spec combinado
(`2026-07-25-motor-nfephp-design.md`) cobrindo NF-e e NFS-e juntas. Na
prática, o trabalho se dividiu em duas etapas sequenciais:

- **Etapa C1** (concluída, neste worktree, `worktree-etapa-c1-nfephp-nfse`,
  ainda não mergeada na `main`) — NFS-e via `nfse-nacional/nfse-php`.
  Entregou toda a infraestrutura **compartilhada** entre os dois motores:
  `NfePhpProvider` (reinterpretação de `registrarEmissor()`/
  `enviarCertificado()` como validação local, já que o NFePHP não registra
  empresa em lugar nenhum — nós somos o emissor), `CertificadoStore`
  (decifra o `.pfx` sob demanda, nunca escreve em disco fora de um arquivo
  temporário efêmero), `CrtResolver` (deriva CRT de
  `Configuracao.regime_tributario`), `EmissaoResultado::erro()` (status
  técnico, distinto de rejeição do fisco), registro do provedor `NFEPHP`
  em `FiscalProviderManager`, e o pill `ERRO` no `StatusPill` do frontend.
- **Etapa C2 (este spec)** — NF-e modelo 55 via `nfephp-org/sped-nfe`, com
  contingência EPEC. É **puramente aditivo** sobre o que a C1 já entrega:
  `NfePhpProvider::emitir()`/`consultar()`/`cancelar()` hoje despacham
  incondicionalmente pra `MotorNfse` e rejeitam `modelo === 'NFE'` com uma
  mensagem clara (mesma guarda de segurança que `SpedyProvider` usa hoje
  pra NF-e não suportada) — esta etapa implementa esse caminho de verdade.

**Sequenciamento decidido pelo usuário (2026-08-10):** desenvolver a Etapa
C2 neste mesmo worktree/branch da C1, não um worktree novo — evita duplicar
`CertificadoStore`/`CrtResolver`/`NfePhpProvider`, e as duas etapas
mergeiam juntas na `main` quando prontas. **Nota de risco registrada, não
resolvida agora:** a `main` recebeu trabalho substancial desde que este
worktree foi criado (Etapas A/B completas e deployadas, mais a feature
NFC-e inteira nesta mesma sessão) — o merge final vai precisar reconciliar
esse desvio, mas isso é problema de quando o merge acontecer, não bloqueia
o desenvolvimento da C2 agora.

Esta rodada de brainstorming **reusa a pesquisa fiscal já feita** (rodada
15 do `PROGRESSO.md`, ~2 semanas atrás) em vez de repeti-la — decisão do
usuário, dado que a pesquisa já cobre o cronograma de obrigatoriedade de
IBS/CBS e o cenário real da oficina (Simples Nacional/CRT=1, dispensado até
04/01/2027). As seções abaixo são as seções B–F do spec combinado original,
extraídas e confirmadas seção por seção nesta sessão, mais uma seção A
reescrita para descrever apenas o que falta **adicionar** ao que a C1 já
construiu.

## Escopo

### Na v1

- NF-e **modelo 55** via `sped-nfe`, contra a SEFAZ-**MG** apenas.
- Contingência **EPEC**, com reconciliação posterior via comando agendado.
- Emissão continua **síncrona** dentro da própria requisição — mesmo nível
  que Spedy/Focus/NFS-e-C1 já usam, nenhum comportamento novo pro frontend.
- PDF **DANFE** via **DomPDF** (não `sped-da`).
- Emissão manual, uma nota por vez — mesma UX das Etapas B/C1 (usuário
  escolhe Serviço OU Venda no formulário; sem vínculo automático com OS).
- Estrutura de IBS/CBS presente no XML, **emitida só para CRT=3** (Lucro
  Presumido/Real) — Simples Nacional (CRT=1, o regime real da oficina) fica
  sem o bloco, dispensado até 04/01/2027.

### Fora da v1

- NFC-e (modelo 65) — já resolvida separadamente, fora deste roteiro
  (feature própria, implementada e deployada nesta mesma sessão via NFC-e
  em Spedy/Focus, não via NFePHP).
- Multi-UF — só MG (oficina real fica em Ilicínea/MG).
- NF-e no Spedy/Focus — já entregue na Etapa B (Focus emitindo; Spedy
  pendente de acesso à doc/sandbox real, guarda de segurança no lugar).
- Preenchimento de IBS/CBS para CRT=1 — depende de NT ainda não publicada.
- `EmissaoOrquestrador` de OS mista e emissão em fila (Horizon) — adiados
  explicitamente desde a Etapa B, reafirmado ao retomar a Etapa C. Só
  fariam sentido junto de um orquestrador que dispara múltiplas notas de
  uma ação só, o que continua fora de escopo.

### Premissas a confirmar fora do código (não bloqueiam o desenvolvimento)

- Alíquota de ISS de Ilicínea — não é relevante pra NF-e (ISS é NFS-e,
  já resolvido pela C1), mantida aqui só como lembrete de pendência ligada
  ao mesmo roteiro.

## A) Arquitetura — o que a C2 adiciona sobre o que a C1 já entrega

### Estado atual (C1, já implementado neste worktree)

```php
// NfePhpProvider (como está hoje)
public function emitir(NotaFiscalData $nota): EmissaoResultado
{
    if ($nota->modelo === 'NFE') {
        return EmissaoResultado::rejeitada(
            'Emissão de NF-e pelo motor NFePHP ainda não disponível neste sistema. Use Focus NFe ou aguarde uma etapa futura.',
            $nota->referenciaExterna,
        );
    }
    return app(MotorNfse::class)->emitir($nota, $this->ambiente);
}

public function consultar(string $referencia): EmissaoResultado
{
    return app(MotorNfse::class)->consultar($referencia, $this->ambiente);
}

public function cancelar(string $referencia, string $motivo): EmissaoResultado
{
    return app(MotorNfse::class)->cancelar($referencia, $motivo, $this->ambiente);
}
```

`consultar()`/`cancelar()` hoje só recebem `$referencia` — sem informação
de modelo, `NfePhpProvider` não teria como saber se deve chamar `MotorNfe`
ou `MotorNfse`. **Este é exatamente o mesmo problema que a feature NFC-e
(desenvolvida em paralelo, direto na `main`, nesta mesma sessão) já
resolveu**: lá, `FiscalProvider::consultar()` ganhou um segundo parâmetro
opcional, `string $modelo = 'NFSE'`, aditivo (default preserva o
comportamento antigo pra quem não passa nada), com `FocusNfeProvider` e
`SpedyProvider` já atualizados pra despachar por ele. Quando este worktree
mergear com a `main`, essa mudança de interface já vai estar lá — a Etapa
C2 deve simplesmente **adotar a mesma assinatura já pronta**, em vez de
inventar um mecanismo paralelo (como codificar o modelo dentro da string de
`referencia_externa`, que seria uma solução ad-hoc e divergente).

### O que a C2 adiciona

```php
// NfePhpProvider (depois da C2 — assinatura de consultar() já alinhada
// com a que a interface FiscalProvider vai ter após o merge com a main)
public function __construct(
    private readonly string $ambiente,
) {}

public function emitir(NotaFiscalData $nota): EmissaoResultado
{
    return $nota->modelo === 'NFE'
        ? app(MotorNfe::class)->emitir($nota, $this->ambiente)
        : app(MotorNfse::class)->emitir($nota, $this->ambiente);
}

public function consultar(string $referencia, string $modelo = 'NFSE'): EmissaoResultado
{
    return $modelo === 'NFE'
        ? app(MotorNfe::class)->consultar($referencia, $this->ambiente)
        : app(MotorNfse::class)->consultar($referencia, $this->ambiente);
}

public function cancelar(string $referencia, string $motivo, string $modelo = 'NFSE'): EmissaoResultado
{
    return $modelo === 'NFE'
        ? app(MotorNfe::class)->cancelar($referencia, $motivo, $this->ambiente)
        : app(MotorNfse::class)->cancelar($referencia, $motivo, $this->ambiente);
}
```

**Nota de implementação:** `cancelar()` não tinha ganhado um `$modelo` na
NFC-e (só `consultar()` precisou, porque é o método usado pelo polling —
ver achado da revisão final de branch daquela feature: `cancelar()` nunca
chamou o provedor de verdade em nenhum provider, é uma lacuna pré-existente
documentada, fora de escopo). A Etapa C2 propõe estender `cancelar()`
também, já que aqui — diferente de Spedy/Focus — cancelar de verdade
importa (é uma chamada local à biblioteca `sped-nfe`, não uma lacuna a
herdar). Avaliar na hora do plano se isso é aditivo o bastante pra não
quebrar `FocusNfeProvider`/`SpedyProvider::cancelar()`.

| Componente novo | Responsabilidade |
|---|---|
| `Services/Fiscal/NfePhp/MotorNfe` | Monta, assina e transmite NF-e via `sped-nfe`, síncrono dentro da própria chamada de `emitir()`. Detecta indisponibilidade e cai pra EPEC internamente (não é um componente separado de decisão — ver Seção C). |
| `Services/Fiscal/Pdf/DanfeRenderer` | XML da NF-e → HTML → DomPDF, com código de barras Code-128C da chave de 44 dígitos. |
| `Console/Commands/ReconciliarContingencia` | Comando agendado (`Schedule::command()` + `->timezone('America/Sao_Paulo')`, mesmo padrão já corrigido na rodada 13) que retransmite notas em `CONTINGENCIA` de hora em hora e alerta antes dos 7 dias. |
| `POST /notas-fiscais/inutilizar-numeracao` (novo endpoint + botão) | Ação administrativa mínima pra fechar buracos de numeração — não é uma classe de domínio grande, só um controller action chamando `$tools->sefazInutiliza()` direto. |

**Reusados sem alteração:** `CertificadoStore`, `CrtResolver`,
`EmissaoResultado::erro()`, `FiscalProviderManager` (já registra
`NFEPHP`), `MotorNfse` (intocado).

**Dependência nova:** `nfephp-org/sped-nfe` (`^5.2`, já confirmado que
contempla a NT 2025.002 até v1.20 — reavaliar campos das versões 1.30/1.40
antes de atender oficina em Regime Normal, não bloqueia CRT=1).

### `NotaFiscalData` — sem alteração

Mesmo raciocínio já documentado no spec combinado: o DTO já carrega
`itens[]` com NCM/CFOP/origem/CST-CSOSN (Etapa B), e `MotorNfe` consulta
`Configuracao::first()` diretamente pra montar o emitente, no mesmo padrão
que `NfeService::montarNotaData()` já usa. CRT não é campo do DTO — deriva
de `regime_tributario` via `CrtResolver` (já existe). IBS/CBS não é bloco
do DTO — calculado dentro de `MotorNfe`, condicionado ao CRT, só entra no
XML quando `CRT === 3`.

## B) Numeração da NF-e

NF-e via NFePHP precisa de série e sequencial próprios, isolados de
`proximo_numero_nf` (que já serve NFS-e/Focus/Spedy) — mesmo padrão de
isolamento que a NFC-e acabou de usar nesta sessão para `serie_nfce`.

**`configuracoes`:**
```
serie_nfe            varchar(3)  default '1'
proximo_numero_nfe   integer     default 1
```

Mesmo `lockForUpdate()` já usado em `NfeService::proximoNumeroNf()`/
`proximoNumeroNfce()`.

**[decisão] O número é alocado dentro de `MotorNfe::emitir()`, imediatamente
antes de transmitir** — não quando o usuário clica em emitir. Encurta ao
máximo a janela em que um número está reservado sem nota associada.
**Nota rejeitada não queima o número**: a retentativa reusa o mesmo `nNF`
(diferente do padrão de Focus/Spedy, onde quem atribui o número final é o
próprio provedor — aqui somos nós o emissor, então essa disciplina é nossa
responsabilidade).

Buracos ainda ocorrem (queda de processo entre alocar e transmitir) — a v1
inclui uma ação mínima de administração usando `sefazInutiliza()` (endpoint
+ botão simples), pra não deixar a oficina travada dependendo do contador.

## C) Contingência EPEC

**Detecção [decisão]:** tenta a transmissão normal primeiro, só cai pra
contingência quando a comunicação falha (timeout ou erro de conexão). Não
consulta `sefazStatus()` antes de cada nota — dobraria a latência de toda
emissão, e o próprio serviço de status cai junto quando a SEFAZ cai.

**Fluxo:** monta a NF-e já com `tpEmis=4`, `dhCont` (momento da detecção) e
`xJust`; chama `$tools->sefazEPEC($xml, $verAplic)`, que vai ao ambiente
nacional, não à SEFAZ-MG. Evento autorizado → nota entra no status novo
`CONTINGENCIA`, e o DANFE já pode ser impresso (documento válido nesse
estado, com indicação de contingência).

**A regra dos 7 dias é o requisito não-óbvio central.** Se o XML normal não
for transmitido em até 7 dias, a SEFAZ bloqueia novos EPEC por "Pendência
de Conciliação" — uma nota esquecida em contingência derruba a contingência
inteira da oficina depois. Por isso:
- comando agendado `nfe:reconciliar-contingencia`, de hora em hora,
  retransmitindo tudo que está em `CONTINGENCIA`;
- alerta ao admin da oficina quando faltarem 2 dias, pelos canais que já
  existem (e-mail e WhatsApp da rodada 10);
- coluna `contingencia_desde timestamptz` em `notas_fiscais` pra calcular
  o prazo.

**A confirmar em homologação, não assumir:** pelo MOC, a nota emitida em
EPEC é retransmitida depois mantendo `tpEmis=4` (a chave de acesso codifica
o tipo de emissão — alterá-lo invalidaria a chave já impressa no DANFE).
Confiança razoável, mas é exatamente o tipo de detalhe que só o teste real
confirma.

## D) PDF (DANFE)

`DanfeRenderer` produz HTML e passa ao DomPDF, seguindo o padrão já usado
em OS e relatórios. **[decisão] Não armazenar PDF** — o XML é a fonte da
verdade, PDF renderizado sob demanda em `GET /notas-fiscais/{id}/pdf`.
Evita arquivo desatualizado quando a nota muda de estado (autorizada →
cancelada) e evita crescimento de storage.

Frontend usa `window.location.origin` pro download (lição da rodada 11:
`NEXT_PUBLIC_API_URL` gravada em build time quebra por CORS cross-
subdomínio).

**Custo registrado:** o DANFE tem layout legalmente especificado (Anexo II
do MOC), incluindo código de barras Code-128C da chave de 44 dígitos —
exige um gerador de barcode embutível e validação visual contra um DANFE
real. É a maior fatia de trabalho evitável do plano (a `sped-da` faria isso
pronto); usuário optou por DomPDF por consistência com o resto do projeto,
com o custo visível e aceito.

## E) Tratamento de erro e status novos

**Já existe (C1):** status `ERRO` (`EmissaoResultado::erro()`, pill
vermelho no `StatusPill`) — distingue falha técnica nossa/de
infraestrutura de uma rejeição de negócio da SEFAZ (`REJEITADA`).

**Novo nesta etapa:** status `CONTINGENCIA` — falta adicionar ao
`StatusPill` do frontend (mesmo arquivo que já tem `ERRO`, só um pill novo,
cor âmbar sugerida por ser um estado "válido mas pendente de finalização",
não vermelho de erro nem verde de autorizado).

| Falha | Quando aparece | Resposta |
|---|---|---|
| Configuração incompleta | Antes de emitir | Bloqueia em "Ativar emissão" (já implementado na C1 via `registrarEmissor()` reinterpretado), não na nota |
| Certificado vencido/inválido | Ao abrir o `.pfx` | Bloqueia + alerta ao admin (já implementado na C1 via `enviarCertificado()`); nunca vira rejeição de nota |
| Payload inválido (schema) | Validação local do `sped-nfe` | `REJEITADA`, **sem retentativa** — repetir o mesmo XML dá o mesmo erro |
| Rejeição da SEFAZ (`cStat`) | Regra de negócio do fisco | `REJEITADA` com `cStat` + motivo literal na tela; correção é humana |
| Falha de comunicação | Timeout/conexão | NF-e → EPEC (esta etapa). NFS-e → retentativa com backoff, já implementado na C1 (EPEC não existe pra NFS-e) |

**O ponto mais delicado é a retentativa.** Timeout na transmissão não
significa que a nota não chegou — a SEFAZ pode ter autorizado e a resposta
é que se perdeu. Retransmitir cego gera nota duplicada com número queimado.

**[decisão]** Antes de qualquer retentativa de NF-e — seja reenvio manual
pelo usuário, seja a reconciliação agendada de contingência (Seção C) —
`MotorNfe` consulta `sefazConsultaChave` com a chave já calculada: se já
está autorizada, apenas concilia o resultado localmente. Mesma lição das
rodadas 7/8 do fluxo de pagamento (ack perdido não significa que o efeito
não aconteceu).

Sem fila, não há `failed()` de job — `NfePhpProvider::emitir()` (via
`MotorNfe`) captura qualquer exceção não prevista (certificado corrompido
no meio da assinatura, erro de biblioteca) e devolve `EmissaoResultado::erro()`
(já existe desde a C1), nunca deixando a nota presa em `PROCESSANDO`
silenciosamente.

## F) Estratégia de teste

### Unitários (única camada executável nesta máquina)

- montagem do payload de NF-e (itens, emitente, CRT, IBS/CBS gated);
- mapeamento de `cStat` → status interno;
- gating de IBS/CBS pelo CRT: bloco ausente com CRT=1, presente com CRT=3
  — protege a decisão de escopo deste spec;
- alocação de número sob concorrência, e reuso do número após rejeição
  (mesmo padrão de teste já usado em `NfeServiceTest::
  test_numeros_unicos_concorrentes`);
- cálculo do prazo de 7 dias da contingência, incluindo a borda do alerta
  (faltando 2 dias).

`CrtResolver` já tem testes da C1 — não precisa de cobertura nova aqui.

### Feature

Escritos, mas **não executáveis localmente** — sem Postgres nesta máquina
(limitação já documentada). Recomenda-se rodar num banco dedicado ou CI
antes de considerar a etapa validada — mesmo precedente da rodada 12
(bug de throttle que só apareceu na revisão final de branch inteira).

### Homologação (onde está a validação real)

`configuracoes.ambiente_fiscal` já distingue `HOMOLOGACAO`/`PRODUCAO`.
Checklist:
1. NF-e autorizada, cancelada e faixa inutilizada;
2. EPEC forçado apontando para endpoint inalcançável, com a reconciliação
   posterior fechando o ciclo;
3. DANFE comparado visualmente com um DANFE real;
4. Confirmar que a retransmissão pós-EPEC mantém `tpEmis=4` (item "a
   confirmar" da Seção C).

Nada vai para produção antes de passar por aqui.

## Riscos conhecidos

1. **`sped-nfe` documenta até a NT v1.20; a NT está na v1.40.** Sem impacto
   pra CRT=1 (regime real da oficina); reavaliar antes de atender oficina
   em Regime Normal.
2. **Layout do DANFE em DomPDF** é a maior incerteza de esforço do plano.
3. **Virar o próprio emissor** transfere pra nós a responsabilidade por
   contingência, atualização de schema e nota rejeitada — hoje absorvida
   pelos provedores pagos (Spedy/Focus). Razão de o NFePHP ser motor
   **opcional por oficina**, nunca substituto obrigatório.
4. **Divergência acumulada entre este worktree e a `main`** — a `main`
   recebeu as Etapas A/B completas mais a feature NFC-e inteira desde que
   este worktree foi criado. O merge final (quando C1+C2 estiverem prontas)
   vai precisar reconciliar isso; não bloqueia o desenvolvimento agora, mas
   é trabalho real a esperar, não uma formalidade.

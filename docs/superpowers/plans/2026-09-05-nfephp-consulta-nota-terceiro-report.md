# Relatório de execução — Estender `ConsultaNotaTerceiroProvider` pro motor NFePHP

**Plano:** `docs/superpowers/plans/2026-09-05-nfephp-consulta-nota-terceiro.md`
**Data:** 2026-09-05 · **Branch:** `main` (direto, sem worktree)
**Status:** DONE_WITH_CONCERNS (as preocupações são de ambiente/limitação
conhecida, não de código entregue — detalhadas no final)

---

## Verificação do grounding antes de codar

O plano dizia estar ancorado no código real do vendor. **Reconferi tudo
antes de escrever qualquer linha**, e bateu 100%:

| Afirmação do plano | Verificado em | Resultado |
|---|---|---|
| `Tools::sefazDistDFe(int $ultNSU=0, int $numNSU=0, ?string $chave=null, string $fonte='AN'): string`, com `$chave` monta `<consChNFe><chNFe>…</chNFe></consChNFe>` | `vendor/nfephp-org/sped-nfe/src/Tools.php:384` | ✅ exato, inclusive o `if (!empty($chave))` que substitui o `<distNSU>` |
| `Tools::sefazManifesta(string $chave, int $tpEvento, …)` | `Tools.php:677` | ✅ (delega pra `sefazEvento('AN', …)`) |
| `Tools::EVT_CIENCIA = 210210` | `Tools.php:36` | ✅ |
| `retDistDFeInt` com `cStat`/`xMotivo` sem enumeração, `loteDistDFeInt` opcional, `docZip` gzip+base64 com atributo `schema` | `schemes/PL_010_V1.30/retDistDFeInt_v1.01.xsd` (lido inteiro) | ✅ `loteDistDFeInt` é `minOccurs="0"`, sequência `maxOccurs="50"`, `docZip` é `xs:base64Binary` com a documentação literal "estará compactado no padrão gZip", `schema` é `use="required"`, e `cStat` é `type="TStat"` — **sem nenhuma enumeração de valores** |
| `resNFe` tem campos direto na raiz | `schemes/PL_010_V1.30/resNFe_v1.01.xsd` | ✅ `chNFe`, `CNPJ`, `CPF`, `xNome`, `IE`, `dhEmi`, `tpNF`, `vNF`, … |
| `MotorNfe` já sabe montar `Tools` (certificado + `configJson()`) | `MotorNfe.php` (`consultar()`/`cancelar()`/`inutilizar()`) | ✅ padrão reaproveitado literalmente |

Nenhuma divergência de grounding — nada de NEEDS_CONTEXT/BLOCKED por esse
motivo. As divergências encontradas foram de **desenho do plano**, não de
fato técnico, e estão listadas em "Desvios" abaixo.

---

## Task 1 — `MotorNfe::consultarNotaRecebida()` + `interpretarRespostaDistDFe()`

**Commit:** `c10f61c feat(fiscal): MotorNfe consulta NF-e de terceiro via Distribuicao DFe`

**Arquivos:**
- `backend/app/Services/Fiscal/NfePhp/MotorNfe.php` (+137)
- `backend/tests/Unit/Fiscal/NfePhp/MotorNfeConsultarNotaRecebidaMappingTest.php` (novo, 167 linhas)

5 testes (o plano pedia 3; acrescentei 2 de robustez: falha ao manifestar
e resposta não-XML).

### RED

```
$ php vendor/bin/phpunit tests/Unit/Fiscal/NfePhp/MotorNfeConsultarNotaRecebidaMappingTest.php
...
3) …::test_resposta_so_com_resnfe_manifesta_ciencia_e_retorna_aguardando
ReflectionException: Method App\Services\Fiscal\NfePhp\MotorNfe::interpretarRespostaDistDFe() does not exist
…
Tests: 5, Assertions: 0, Errors: 5, Risky: 2.
```

Falha pelo motivo certo: o método sob teste não existe (não é erro de
fixture, de namespace nem de container).

### GREEN

```
$ php vendor/bin/phpunit tests/Unit/Fiscal/NfePhp/MotorNfeConsultarNotaRecebidaMappingTest.php
.....                                                               5 / 5 (100%)
OK (5 tests, 9 assertions)
```

Suíte inteira depois da task: `Tests: 256, Assertions: 606, Errors: 7`
(ver "Ambiente" — os 7 são pré-existentes e alheios).

---

## Task 2 — `MotorNfe::listarNotasRecebidas()` + `mapearListaDistDFe()`/`resumoDeDocumento()`

**Commit:** `babd96e feat(fiscal): MotorNfe lista notas recebidas via Distribuicao DFe (best-effort)`

**Arquivos:**
- `backend/app/Services/Fiscal/NfePhp/MotorNfe.php` (+166)
- `backend/tests/Unit/Fiscal/NfePhp/MotorNfeListarNotasRecebidasMappingTest.php` (novo, 148 linhas)

6 testes (o plano pedia 2; acrescentei: evento descartado, lote ausente,
docZip corrompido pulado sem derrubar os demais, resposta ilegível).

### RED

```
$ php vendor/bin/phpunit tests/Unit/Fiscal/NfePhp/MotorNfeListarNotasRecebidasMappingTest.php
ReflectionException: Method App\Services\Fiscal\NfePhp\MotorNfe::mapearListaDistDFe() does not exist   (×6)
Tests: 6, Assertions: 0, Errors: 6.
```

### GREEN

```
$ php vendor/bin/phpunit tests/Unit/Fiscal/NfePhp/MotorNfeListarNotasRecebidasMappingTest.php
......                                                              6 / 6 (100%)
OK (6 tests, 20 assertions)
```

Suíte inteira: `Tests: 262, Assertions: 626, Errors: 7` (mesmos 7).

---

## Task 3 — `NfePhpProvider implements ConsultaNotaTerceiroProvider`

**Commit:** `4de6671 feat(fiscal): NfePhpProvider implementa ConsultaNotaTerceiroProvider via Distribuicao DFe`

**Arquivos:**
- `backend/app/Services/Fiscal/Providers/NfePhpProvider.php` (+27/−1)
- `backend/tests/Unit/Fiscal/NfePhpProviderTest.php` (+78, acrescentado ao arquivo existente como o plano mandava)

4 testes novos (o plano pedia 2): o `instanceof` do contrato — que é o que
`EntradaNfController` realmente checa —, as duas delegações com o ambiente
certo, e a propagação da exceção de falha.

### RED

```
$ php vendor/bin/phpunit tests/Unit/Fiscal/NfePhpProviderTest.php
1) …::test_consultar_nota_recebida_despacha_para_motor_nfe_com_o_ambiente
Error: Call to undefined method App\Services\Fiscal\Providers\NfePhpProvider::consultarNotaRecebida()
2) …::test_listar_notas_recebidas_despacha_para_motor_nfe_com_o_ambiente
Error: Call to undefined method …::listarNotasRecebidas()
1) …::test_provider_implementa_o_contrato_de_consulta_de_nota_de_terceiro   (Failure)
2) …::test_falha_ao_listar_propaga_excecao_em_vez_de_lista_vazia            (Failure)
Tests: 12, Assertions: 13, Errors: 2, Failures: 2, Risky: 3.
```

Os 8 testes pré-existentes do arquivo continuaram passando durante o RED —
prova de que o RED é dos testes novos, não de quebra colateral.

### GREEN

```
$ php vendor/bin/phpunit tests/Unit/Fiscal/NfePhpProviderTest.php
............                                                      12 / 12 (100%)
OK (12 tests, 16 assertions)
```

Suíte inteira: `Tests: 266, Assertions: 631, Errors: 7`.

---

## Task 4 — `PROGRESSO.md` / `TAREFAS.md`

**Commit:** `6767704 docs: registra Rodada 33 (ConsultaNotaTerceiroProvider no motor NFePHP)`

- `PROGRESSO.md`: nova seção "Rodada 33" no topo, separando explicitamente
  **o que foi confirmado lendo vendor/XSD** de **o que segue não confirmado
  contra a SEFAZ real**, mais os desvios do plano e a limitação de paginação.
- `TAREFAS.md`: item 3 marcado `[x]`, com a sobra conhecida e a incerteza
  registradas no próprio item (não só no PROGRESSO).

**Cuidado necessário aqui (ver "Ambiente"):** `TAREFAS.md` tinha alterações
não commitadas de OUTRA sessão rodando em paralelo. Não commitei o trabalho
alheio: montei o blob do índice com `git hash-object` + `git update-index
--cacheinfo` contendo só a minha alteração (item 3), deixando o hunk alheio
(item 4) intacto no working tree. Verificado com `git diff --cached` vs
`git diff` antes de commitar. A outra sessão depois reescreveu o arquivo por
conta própria e o meu item 3 sobreviveu intacto.

---

## Autorrevisão do diff completo

Reli os três commits de código (`git show c10f61c babd96e 4de6671`).

### 1. Nenhum `cStat` numérico como sinal de sucesso/falha — CONFIRMADO

Busca por `cStat` e por literais `'1xx'` em todas as linhas adicionadas de
código de produção:

```
$ git show c10f61c babd96e 4de6671 | grep -E '^\+' | grep -E "cStat|'1[0-9][0-9]'" | grep -vE '^\+\s*(\*|//)'
+                'cStat'   => (string) ($sxml->xpath('//nfe:cStat')[0] ?? ''),
+          …<cStat>138</cStat>…   (×4, fixture XML de teste)
+          …<cStat>137</cStat>…   (×2, fixture XML de teste)
```

A **única** ocorrência em código de produção está dentro do array de
contexto de um `Log::info()`. Nenhuma comparação, nenhum `match`, nenhum
`in_array`. Os `137`/`138` das fixtures são ruído realista de XML — nenhuma
asserção de nenhum teste depende deles (dá pra trocar por qualquer número
que os testes continuam passando, porque o que decide é a presença/ausência
de `docZip`). Isso está dito nos comentários de ambos os arquivos de teste,
pra ninguém "corrigir" isso pra uma comparação numérica depois.

O sinal usado é o estrutural do XSD: `loteDistDFeInt` é `minOccurs="0"`,
logo ausência de `docZip` **é** "nenhum documento", qualquer que seja o
código.

### 2. Achado real da autorrevisão (corrigido)

`mapearListaDistDFe()` devolvia `[]` quando a resposta não era XML válido.
Isso reintroduzia, pela porta dos fundos, exatamente o bug que o contrato de
`ConsultaNotaTerceiroProvider` foi escrito pra impedir: uma SEFAZ devolvendo
lixo/HTML de erro apareceria na tela como "nenhuma nota recebida". Corrigido
em TDD (RED: `Failed asserting that exception of type "RuntimeException" is
thrown.` → GREEN: `OK (6 tests, 21 assertions)`):

**Commit:** `ddadf24 fix(fiscal): resposta ilegivel ao listar notas recebidas e falha, nao lista vazia`

`[]` agora significa só uma coisa: a SEFAZ respondeu direito e não há
documentos.

### 3. Outros pontos revisados sem achado

- `declare(strict_types=1)` em todos os arquivos tocados: já estava (arquivos
  pré-existentes) / presente nos novos.
- `interpretarRespostaDistDFe()` devolve `ERRO` (não `NAO_ENCONTRADA`) pra
  resposta ilegível — mesma disciplina do item 2, já estava certo.
- Nenhum item é inventado a partir do `resNFe`: o resumo não tem `<det>`
  nenhum, e o código nunca tenta derivar itens dele.
- `php -l` limpo nos dois arquivos de produção.

---

## Desvios do plano (todos deliberados, com motivo)

1. **`listarNotasRecebidas()` lança em vez de devolver `[]` em falha.**
   O plano mandava `catch (\Throwable) { return []; }`. Isso **viola o
   contrato escrito na própria interface**
   (`ConsultaNotaTerceiroProvider.php:18-21`: "@throws \RuntimeException …
   nunca deve devolver `[]` silenciosamente pra isso") e quebraria
   `EntradaNfController::recebidas():377-384`, que distingue "erro do
   provedor" de "não tem nota" **exclusivamente** por essa exceção. Seguir o
   plano aqui teria entregue um bug conhecido.

2. **`Configuracao::first()` dentro do try/catch** (o plano deixava fora, nos
   dois métodos). O próprio `MotorNfe.php` já documenta essa correção em
   `consultar()`, `cancelar()`, `retransmitir()` e `inutilizar()`: sem banco,
   `Model::resolveConnection()` lança `\Error` (não `\Exception`), que
   escaparia incapturado. Mesmo achado, mesma correção.

3. **Mais testes que o plano pedia** (15 no total vs. 7). Os extras cobrem os
   caminhos de degradação que são o coração desta mudança: falha ao
   manifestar não pode derrubar a consulta; docZip corrompido não pode
   derrubar o lote; evento não é nota; resposta ilegível é falha.

4. **Um 5º commit não previsto** (`ddadf24`), fruto da autorrevisão — ver
   acima.

Nada disso exigiu inventar comportamento fiscal: os três primeiros são
consistência com código e contratos já existentes no repo.

---

## Ambiente / o que NÃO pôde ser verificado

**Suíte final:** `php vendor/bin/phpunit tests/Unit` → `Tests: 266,
Assertions: 632, Errors: 7`. Todos os 7 erros são **pré-existentes e alheios
a esta rodada**:

- 3 × `CertificadoStoreTest` — `openssl_csr_sign(): Argument #1 must be …,
  bool given`. Ambiente OpenSSL local, já esperado pelo briefing.
- 4 × `ConciliarFiscalNotaEntradaJobTest` — usa `RefreshDatabase` e não há
  Postgres nesta máquina. **Esse arquivo não é meu**: apareceu no working
  tree durante a execução, vindo de outra sessão trabalhando na `main` em
  paralelo (commits `4397bf7` e `e01d70e` entraram entre o meu Task 3 e o meu
  Task 4). Confirmei que os 4 erros são de `RefreshDatabase`/Postugres, não do
  meu código, lendo o stack trace.

**Não verificável sem certificado + rede reais** (documentado no código, no
`PROGRESSO.md` e no `TAREFAS.md`, não escondido):

- Se uma consulta `consChNFe` feita pelo próprio CNPJ destinatário devolve o
  `procNFe` completo direto, ou se depende de manifestação prévia. Os dois
  cenários são tratados; o desconhecido degrada pro caminho seguro
  (`AGUARDANDO_MANIFESTACAO`), nunca pro que inventaria dados.
- O comportamento real do webservice sob os XMLs de fixture: as fixtures
  seguem o XSD oficial do pacote instalado, mas XSD-conforme não é o mesmo
  que "é isso que a SEFAZ manda". O parsing está certo *para o schema*.

---

## Preocupações

1. **`listarNotasRecebidas()` é best-effort de verdade** — sempre varre do
   NSU 0 e fica com o primeiro lote (máx. 50 docs pelo XSD), ignorando
   `ultNSU`/`maxNSU`. Foi decisão explícita do plano (YAGNI: nenhuma oficina
   em produção usa o motor NFePHP), e está documentado em 3 lugares, mas é
   uma armadilha real pra quem for o primeiro a usar isso com volume. Uma
   oficina com mais de 50 notas de entrada não vistas simplesmente não vê as
   excedentes — **sem nenhum aviso na tela**. Se isso for pra produção antes
   da paginação, valeria pelo menos expor `maxNSU > ultNSU` como um aviso na
   UI.
2. **O caminho de manifestação nunca foi exercido contra a SEFAZ.** O código
   está certo em relação à API do vendor (assinatura confirmada), mas se a
   Distribuição DFe se comportar diferente do esperado pro próprio
   destinatário, o usuário pode ficar num laço de "consulte de novo em
   instantes" que nunca completa. Só um teste com certificado real resolve.
3. **Sessão concorrente na mesma branch.** Trabalhei em `main` com outra
   sessão commitando ao mesmo tempo. Isolei os meus commits por caminho
   explícito e verifiquei o índice antes de cada commit, mas vale o usuário
   conferir se o `TAREFAS.md`/`PROGRESSO.md` final ficou coerente entre as
   duas rodadas.

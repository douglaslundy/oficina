# Campos fiscais em produtos + importação de XML que os preenche — design

## Contexto

Esta é a **etapa A** de três, todas pré-requisito da emissão de NF-e:

- **Etapa A (este spec)** — campos fiscais em `produtos` e correção da
  importação de NF-e por XML para preenchê-los.
- **Etapa B** — refactor compartilhado (`NotaFiscalData` com `itens[]`,
  `EmissaoOrquestrador` da OS mista, emissão em fila), **NF-e via API do
  Spedy/Focus**, e correção de 5 defeitos encontrados nos dois providers.
- **Etapa C** — motor NFePHP, spec já escrito em
  `2026-07-25-motor-nfephp-design.md`.

A ordem foi definida assim porque **Spedy e Focus são os motores oficiais**
e hoje não emitem NF-e, só NFS-e. Fazer o NFePHP primeiro faria a única
capacidade de emitir nota de peça chegar pelo motor opcional e ainda não
validado contra a SEFAZ.

### O problema que esta etapa resolve

Duas descobertas da investigação:

**1. `produtos` não tem nenhum campo fiscal.** A busca

```
grep -riE "ncm|cfop|csosn|cest|origem_mercadoria" app/ database/migrations/
```

retorna vazio no projeto inteiro. A tabela tem `nome`, `sku`, `categoria`,
`unidade`, quantidades e preços. Sem NCM, origem e situação tributária, a
SEFAZ rejeita a NF-e — não é detalhe de implementação, é dado inexistente.

**2. A importação de NF-e por XML já recebe esses dados e os descarta.**
`NotaEntradaXmlParser::parse()` (linhas 52-57) extrai de `$det->prod`
apenas `cEAN`, `xProd`, `qCom` e `vUnCom`. São descartados, estando ali ao
lado: `prod->NCM`, `prod->CFOP`, `prod->CEST`, `prod->uCom` e, dentro de
`det->imposto->ICMS`, o `orig` e o `CST`/`CSOSN`. A tabela
`notas_entrada_itens` também não tem colunas para nada disso.

Ou seja: o dado fiscal autoritativo de cada peça já entra no sistema,
assinado digitalmente pelo fornecedor, e é jogado fora. Recuperá-lo elimina
quase inteiramente a necessidade de classificar peça a peça na mão.

### Contexto do usuário — sem contador

O usuário declarou não ter contador e optou por confiar na pesquisa
documental. Isso torna obrigatório separar, em todo este spec, **o que é
tabela legal verificável** do que é **juízo de classificação** — e dizer
claramente o que a pesquisa não cobre. Ver a seção "Limites" ao final.

## Base legal verificada

| Item | Fonte | Resultado |
|---|---|---|
| CSOSN | Ajuste SINIEF 03/2010, Anexo Único, Tabela B (texto do CONFAZ) | Códigos com ST: **201, 202, 203, 500** |
| CST | Tabela B do Anexo do Convênio SINIEF s/nº de 15/12/1970 | Com ST: **10, 30, 60, 70** |
| Origem da mercadoria | Tabela A do mesmo anexo | Códigos **0 a 8** |

Descrições dos CST relevantes: **10** tributada e com cobrança do ICMS por
ST; **30** isenta ou não tributada e com cobrança do ICMS por ST; **60**
ICMS cobrado anteriormente por ST (caso dominante em peça de reposição);
**70** tributada com redução de base de cálculo e com cobrança do ICMS
por ST.

Nota sobre a referência do CSOSN: o Ajuste SINIEF 14/2019 revogou, a partir
de 01/01/2022, o Anexo I do Ajuste SINIEF 07/2005 — a referência válida
hoje é o **Ajuste SINIEF 03/2010**.

### A tabela CST é instável — e isso muda o design

O **Ajuste SINIEF 39/2023** criaria os CST **12, 13, 52, 72, 74** (todos de
ST) a partir de 01/04/2024. O **Ajuste SINIEF 20/2024** (09/07/2024)
**revogou** essa criação. Há fonte estadual publicando a tabela **com**
esses códigos — ou seja, versões conflitantes circulando, e nada impede que
um ajuste futuro os ressuscite.

**[decisão] Não usar lista fixa com `else` → NORMAL.** A regra de derivação
é de três estados, não dois:

```
CST/CSOSN em conjunto conhecido de ST      → 'ST'
CST/CSOSN em conjunto conhecido de não-ST  → 'NORMAL'
código desconhecido                        → NÃO decide; vira pendência
```

Uma lista fixa envelheceria **silenciosamente**: CST 12 chegaria num XML,
cairia no `else` e a peça seria classificada como NORMAL sendo ST. É a
mesma doença do defeito nº 3 catalogado em Spedy/Focus para a etapa B
(`default` do `match` engolindo o caso não previsto) — não faz sentido
repeti-la numa tabela que a legislação comprovadamente altera.

## A) Modelo de dados

### `produtos` — colunas novas

```
ncm                varchar(8)   nullable    -- 8 dígitos
cest               varchar(7)   nullable    -- 7 dígitos; só se aplica a ST
origem             smallint     nullable    -- 0..8 (Tabela A)
tributacao_icms    varchar(10)  nullable    -- 'NORMAL' | 'ST'
fiscal_fonte       varchar(10)  nullable    -- 'MANUAL' | 'XML' | 'PADRAO'
fiscal_revisado_em timestamptz  nullable
```

`fiscal_fonte` e `fiscal_revisado_em` existem por causa da tela de
pendências: sem eles não há como distinguir *"NCM conferido por uma
pessoa"* de *"NCM que veio do padrão da categoria e ninguém olhou"* — os
dois seriam apenas um NCM preenchido.

### `notas_entrada_itens` — colunas novas

```
ncm_xml        varchar(8)  nullable
cfop_xml       varchar(4)  nullable
cest_xml       varchar(7)  nullable
origem_xml     smallint    nullable
cst_csosn_xml  varchar(4)  nullable
unidade_xml    varchar(6)  nullable
```

Guardar o valor bruto declarado pelo fornecedor **naquela entrada
específica** é a trilha de auditoria: permite voltar e ver o que ele disse,
independentemente do que acabou ficando no cadastro do produto.

### Multi-tenancy

`Produto` usa `HasTenantScope` e tem `oficina_id` (adicionado em
`2026_05_31_100005_add_oficina_id_to_tenant_tables.php`). As duas tabelas
novas seguem o mesmo padrão — `oficina_id` + `HasTenantScope` no model —
por serem dados de negócio por oficina. `notas_entrada` já é tenant-scoped,
e as colunas novas em `produtos` e `notas_entrada_itens` herdam o escopo das
tabelas que já existem.

### `produto_fiscal_divergencias` — tabela nova

```
id             uuid PK
oficina_id     uuid FK oficinas.id
produto_id     uuid FK produtos.id
nota_entrada_id uuid FK notas_entrada.id
campo          varchar(20)   -- 'ncm' | 'cest' | 'origem' | 'tributacao_icms'
valor_atual    varchar(20)
valor_xml      varchar(20)
criado_em      timestamptz default now()
resolvido_em   timestamptz nullable
resolucao      varchar(10) nullable  -- 'MANTEVE' | 'ACEITOU_XML'

índices: (produto_id), (resolvido_em)
```

### `categoria_padrao_fiscal` — tabela nova (por oficina)

```
id               uuid PK
oficina_id       uuid FK oficinas.id
categoria        varchar(40)
ncm              varchar(8)  nullable
origem           smallint    nullable
tributacao_icms  varchar(10) nullable

único: (oficina_id, categoria)
```

Semeada com as sete categorias padrão do sistema (Filtros, Óleo/Fluidos,
Freios, Suspensão, Elétrica, Motor, Outros), **com os valores em branco**.

**[decisão] Não embutir códigos NCM inventados.** NCM é classificação legal;
um código errado é classificação incorreta perante o fisco, com o agravante
de *parecer* preenchido. Quem preenche essas sete linhas é o usuário, uma
vez. A troca vale a pena: sete decisões em vez de trezentas, e a importação
de XML preenche os produtos reais com dado do fornecedor daí em diante.

## B) Parser

`NotaEntradaXmlParser::parse()` passa a extrair de cada `det`:

- de `prod`: `NCM`, `CFOP`, `CEST`, `uCom` (além dos quatro atuais);
- de `imposto->ICMS`: `orig` e `CST` **ou** `CSOSN`.

O nó do ICMS tem **nome variável** (`ICMS00`, `ICMS60`, `ICMSSN500`, …), o
que exige iterar os filhos de `imposto->ICMS` em vez de acessar por nome
fixo — é o erro clássico neste parse.

A derivação de `tributacao_icms` segue a regra de três estados definida
acima.

### CFOP não vira coluna de `produtos`

**[decisão]** CFOP não é atributo da mercadoria, é atributo da **operação**:
a mesma peça tem CFOP diferente vendida dentro de MG ou para outro estado,
com ou sem ST. Guardar CFOP no produto convida a copiar o da nota de
entrada — que é código de **compra** (5405, 6404…) — e emitir uma venda com
ele resulta em rejeição.

Nesta etapa o CFOP é apenas **capturado** como auditoria em `cfop_xml`. O
CFOP de saída é **derivado** no momento da emissão, na etapa B.

### Política de conflito

| Estado do campo no produto | Ação |
|---|---|
| Vazio | Preenche com o valor do XML; `fiscal_fonte = 'XML'` |
| Preenchido e **igual** ao XML | Nada |
| Preenchido e **diferente** do XML | **Mantém o atual** e cria `produto_fiscal_divergencias` |

Protege correções feitas de propósito sem esconder que existe conflito —
fornecedores erram classificação com frequência, e às vezes quem está certo
é o fornecedor.

## C) Telas

### Formulário de produto

Bloco novo "Dados fiscais": NCM, CEST, origem (select 0–8 com as descrições
da Tabela A) e tributação do ICMS (Normal/ST). Cada campo exibe a
procedência do valor ("veio da NF-e do fornecedor X", "padrão da categoria
Freios", "preenchido manualmente"). Salvar manualmente grava
`fiscal_fonte = 'MANUAL'` e carimba `fiscal_revisado_em` — é isso que tira
o produto da lista de pendências.

### Pendências fiscais (dentro de Produtos)

Lista os produtos ativos em um destes estados:

| Situação | Pill |
|---|---|
| Sem NCM | vermelho (`--danger`) |
| NCM herdado do padrão da categoria, sem revisão | âmbar (`--accent`) |
| Divergência aberta com XML de fornecedor | âmbar, com os dois valores lado a lado |
| CST/CSOSN desconhecido vindo do XML | âmbar |

O vermelho sinaliza que o produto **impedirá** a emissão de NF-e. O bloqueio
em si é comportamento da etapa B (decisão aprovada: parar a emissão e
apontar os itens incompletos, em vez de queimar numeração numa rejeição
certa da SEFAZ). Nesta etapa a tela apenas identifica e permite corrigir.

Produto sem NCM recebe `danger-row`, seguindo a mesma regra visual já usada
para cliente devedor e estoque crítico. Resolver divergência é escolha
binária na própria linha (manter o atual / aceitar o do fornecedor); ambas
gravam `resolvido_em` e marcam o produto como revisado.

**[decisão] Sem badge novo na sidebar.** "Produtos" já carrega o badge de
alerta de estoque; um segundo contador no mesmo item transforma o número
num borrão sem significado. A contagem aparece na aba, dentro do módulo.

### Padrões por categoria (Configurações)

Sete linhas com NCM, origem e tributação padrão. Nascem vazias.

### Importação de XML

Passa a exibir, por item, os dados fiscais captados, sinalizando os que
geraram divergência ou vieram com código desconhecido. Hoje mostra apenas
descrição, quantidade e valor — o usuário importa às cegas do ponto de
vista fiscal.

## D) Tratamento de erro

**Princípio: a importação nunca falha inteira por causa de dado fiscal.**
Ela existe para dar entrada em estoque; enriquecer o cadastro fiscal é
ganho secundário e não pode derrubar o objetivo principal.

- Item sem CEST, sem NCM, ou com nó de ICMS não reconhecido → importa
  normalmente e registra a pendência.
- Valor malformado (NCM sem 8 dígitos, origem fora de 0–8, CEST sem 7
  dígitos) → **não grava** o valor; registra pendência. Guardar lixo num
  campo fiscal é pior que deixá-lo vazio: o vazio é visível, o lixo passa
  por preenchido.
- XML corrompido → mantém o comportamento atual (exceção com mensagem).

## E) Estratégia de teste

Toda a lógica desta etapa é pura — parser, derivação de tributação,
detecção de divergência. Isso significa que os testes **rodam de fato na
máquina do desenvolvimento**, sem depender do Postgres indisponível
localmente. É a primeira das três etapas com cobertura automatizada
realmente executável.

**Fixtures de XML:** `ICMS00` (normal), `ICMS60` (ST — caso dominante),
`ICMSSN500` (Simples com ST), item sem GTIN, item sem CEST, e **um item com
CST 12** — o código revogado, provando que ele vira pendência em vez de ser
silenciosamente classificado como NORMAL.

**Divergência**, os três caminhos: campo vazio preenche; valor igual não faz
nada; valor diferente cria divergência **sem** tocar no cadastro.

**Validação de formato:** NCM com 7 ou 9 dígitos, origem 9, CEST curto —
todos devem virar pendência, nunca gravação.

## F) Limites — o que a pesquisa documental não cobre

Dado que o usuário não tem contador, estes três pontos precisam ficar
explícitos:

1. **Qual NCM classifica uma peça específica.** Não é consulta de tabela, é
   juízo sobre a mercadoria concreta. **Mitigado no fluxo real:** o NCM já
   vem assinado na NF-e de cada fornecedor, e é justamente isso que esta
   etapa passa a ler. O padrão por categoria é apenas rede de segurança
   para produto cadastrado manualmente, sem nota de entrada.
2. **Alíquota de ISS de Ilicínea/MG.** Lei municipal, não indexada em
   fontes públicas. A **prefeitura** informa — não requer contador.
   Relevante para as etapas B e C, não para esta.
3. **Se uma peça está na lista de ST de Minas Gerais.** A lista é estadual
   (Anexo do RICMS/MG). O CST declarado pelo fornecedor é evidência forte,
   não prova.

## Fora do escopo

- Emissão de NF-e — etapa B.
- Derivação de CFOP de saída — etapa B (depende de UF do destinatário).
- Motor NFePHP — etapa C.
- Reclassificação em massa do estoque existente por outro critério que não
  o padrão por categoria ou a importação de XML.

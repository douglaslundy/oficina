# Entrada de Nota Fiscal no Estoque — Design (Fase 1)

## Contexto e motivação

Hoje o único jeito de dar entrada em estoque é manual, produto por produto (`EstoqueController::entrada`,
botão "+ Entrada" em `/produtos`). Quando chega uma compra de fornecedor com várias peças, o usuário
precisa lançar item a item na mão. O pedido: ler a nota fiscal do fornecedor e cadastrar/alimentar o
estoque de todos os produtos da nota de uma vez.

## Escopo desta fase

**Fase 1 (este documento, implementar agora):** importar a nota a partir do **arquivo XML da NF-e**
que o fornecedor envia junto da compra (e-mail/WhatsApp, prática já universal no B2B brasileiro).
Sem depender de nenhum provedor externo novo.

**Fase 2 (fora de escopo aqui, só documentada):** ler a chave de acesso (44 dígitos, o código de barras
do DANFE) e consultar a nota **diretamente na SEFAZ**, via `NFePHP`/`sped-nfe`, usando o certificado A1
que a oficina já sobe hoje para emissão de NFS-e (tabela `emissores_fiscais`). Tecnicamente viável sem
contratar um provedor pago (Focus MDe, NFe.io Consulta Irrestrita, etc. são atalhos comerciais para o
mesmo webservice público da SEFAZ), mas exige: submeter evento de "ciência da operação" antes de a
SEFAZ liberar o XML completo (fluxo de Manifestação do Destinatário), comunicação SOAP com
particularidades por UF, e gestão de NSU/protocolo de distribuição. Tamanho de projeto comparável ao
que já foi feito em `feat/emissao-fiscal-multi-provedor`. Ao retomar, esta fase deve reaproveitar o
parser e o fluxo de confirmação construídos na Fase 1 — a única mudança é *de onde* o XML vem.

## Decisões (aprovadas com o usuário)

- Matching de produto existente × novo: por **código de barras/EAN** (`cEAN`/`cEANTrib` no XML) contra
  um novo campo `produtos.codigo_barras`. Não por SKU (código do fornecedor é inconsistente entre
  fornecedores).
- Sempre existe uma **tela de conferência editável** antes de gravar — nenhuma importação é automática
  sem revisão humana.
- Preço de venda de produto novo: sugerido por **markup padrão configurável** sobre o custo do XML,
  editável linha a linha antes de confirmar.
- Atualizar `preco_custo` de produto já existente com o valor do XML: **configurável** (liga/desliga em
  Configurações), não hardcoded.
- Fornecedor: **não** cria cadastro de Fornecedores agora. Nome/CNPJ do emitente ficam só no registro
  da própria nota de entrada.

## Modelo de dados

### `produtos` (alteração)
- `codigo_barras VARCHAR(20) NULL` — único por oficina (índice composto `oficina_id + codigo_barras`,
  mesma convenção de `HasTenantScope` já usada no resto do schema).

### `notas_entrada` (nova)
```sql
CREATE TABLE notas_entrada (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  oficina_id UUID NOT NULL REFERENCES oficinas(id),
  numero_nf VARCHAR(20),
  serie VARCHAR(5),
  chave_acesso VARCHAR(44),          -- nullable; único por oficina quando presente
  fornecedor_nome VARCHAR(150),
  fornecedor_cnpj VARCHAR(18),
  valor_total NUMERIC(10,2),
  data_emissao DATE,
  xml_original TEXT,                 -- guardado para auditoria/reprocessamento
  usuario_id UUID REFERENCES usuarios(id),
  criado_em TIMESTAMPTZ DEFAULT NOW()
);
-- unique index (oficina_id, chave_acesso) WHERE chave_acesso IS NOT NULL
```

### `notas_entrada_itens` (nova)
```sql
CREATE TABLE notas_entrada_itens (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  nota_entrada_id UUID REFERENCES notas_entrada(id) ON DELETE CASCADE,
  produto_id UUID REFERENCES produtos(id),
  codigo_barras_xml VARCHAR(20),
  descricao_xml VARCHAR(200),
  quantidade NUMERIC(10,2),
  valor_unitario NUMERIC(10,2),
  produto_criado BOOLEAN DEFAULT FALSE
);
```

### `movimentacoes_estoque` (alteração)
- `nota_entrada_id UUID NULL REFERENCES notas_entrada(id)` — mesmo padrão de `os_id`, para rastrear a
  origem da movimentação.

### `configuracoes` (alteração)
- `markup_padrao_entrada_nf NUMERIC(5,2) DEFAULT 40.00`
- `atualizar_custo_entrada_nf BOOLEAN DEFAULT TRUE`

## Backend

### Parser (`app/Services/NotaEntradaXmlParser.php`)
- `SimpleXMLElement`/`DOMDocument` puro — **sem** dependência nova no Fase 1 (NFePHP só entra na Fase 2,
  para comunicação SOAP com a SEFAZ; ler um XML de NF-e já finalizado não precisa dele).
- Lê `infNFe/@Id` (chave de acesso, 44 dígitos após o prefixo `NFe`), `emit` (nome/CNPJ fornecedor),
  `ide` (número, série, data de emissão), cada `det` (`cEAN` ou `cEANTrib` como fallback, `xProd`,
  `qCom`, `vUnCom`), `total/ICMSTot/vNF`.
- Suporta NF-e modelo 55 (produto). Não trata NFC-e/NFS-e — fora do caso de uso (compra de peças).
- Erros de XML malformado ou de modelo incompatível retornam mensagem clara (`422`), sem tentar
  "adivinhar" estrutura.

### `EstoqueService` (novo método)
- `entradaPorNotaFiscal(NotaEntrada $nota, array $itensConfirmados, string $usuarioId): void` —
  reaproveita a mesma mecânica transacional de `entradaManual`/`baixarEstoqueOs` (lock, increment,
  `MovimentacaoEstoque::create` com `nota_entrada_id`).

### Endpoints (`routes/api.php`)
- `POST /api/entradas-nf/parse` — multipart, recebe o arquivo XML. **Não grava nada.** Parseia, faz o
  matching por código de barras, calcula sugestões (categoria "Outros", unidade "Un", preço de venda via
  markup) e devolve o preview. Se a chave de acesso já existir em `notas_entrada` da oficina, o preview
  inclui um aviso (não bloqueia o parse, mas o frontend impede confirmar).
- `POST /api/entradas-nf` — payload já revisado pelo usuário (nota + lista de itens finais, com
  `produto_id` quando é match ou dados completos quando é produto novo). Dentro de `DB::transaction`:
  cria a nota, cria produtos novos, trava e incrementa os existentes via `EstoqueService`, grava os
  itens e as movimentações, atualiza `preco_custo` se a config estiver ligada. Rejeita com `422` se a
  chave de acesso já foi usada por essa oficina (checagem final, evita corrida entre parse e confirm).
- `GET /api/entradas-nf` — histórico paginado (número, fornecedor, data, valor, qtd itens).
- `GET /api/entradas-nf/{id}` — detalhe com itens.

## Frontend

### Página `/produtos/entrada-nf`
- Passo 1: dropzone/input de arquivo XML → `POST /entradas-nf/parse` → guarda o preview no estado.
- Passo 2 (tela de conferência): cabeçalho com dados da nota (número, fornecedor, CNPJ, data, valor do
  XML vs soma calculada dos itens — alerta visual se não bater); tabela editável por item (código de
  barras, descrição, badge "Existente"/"Novo", categoria — só editável se novo, quantidade, preço de
  custo, preço de venda — só editável/habilitado se novo, botão remover linha).
- Botão "Confirmar Entrada" desabilitado se a nota já foi lançada (aviso do preview) ou se a lista de
  itens ficar vazia. Ao confirmar, `POST /entradas-nf`, toast de sucesso com resumo (N produtos criados,
  M atualizados) e redireciona para `/produtos`.
- Botão "+ Lançar NF" adicionado ao topo de `/produtos/page.tsx`, ao lado da busca.

### Configurações (`/configuracoes`)
- Dois campos novos na seção de estoque: "Markup padrão para produtos novos (%)" e um toggle
  "Atualizar custo do produto ao lançar entrada por NF".

## Fora de escopo (Fase 1)
- Cadastro de Fornecedores como entidade própria.
- Leitura de chave de acesso / consulta à SEFAZ (Fase 2).
- NFC-e, CT-e ou qualquer modelo de documento fiscal que não seja NF-e 55 de compra.
- Edição/estorno de uma entrada já confirmada (se precisar corrigir, ajusta o produto manualmente
  depois — mesma limitação que já existe hoje para entrada manual).

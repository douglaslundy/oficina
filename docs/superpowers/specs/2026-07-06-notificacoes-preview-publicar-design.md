# Preview e Publicação de Notificações (SaaS Admin)

**Data:** 2026-07-06
**Escopo:** Adicionar botão "Visualizar" (preview fiel do layout exibido na oficina) e fluxo de publicação (rascunho → publicada) na página `/saas-admin/notificacoes`.

---

## Contexto

A página `/saas-admin/notificacoes` permite criar notificações exibidas dentro das oficinas (tenants). Hoje o único campo de controle é o boolean `ativo` (mostrado como pill "Ativa"/"Inativa"), que já é o que filtra a visibilidade em `GET /notificacoes/ativas` (rota tenant). Não existe distinção entre rascunho e publicada — uma notificação criada com `ativo=true` já aparece imediatamente na oficina, sem etapa de revisão. Também não existe nenhuma forma de o admin ver como a notificação vai aparecer antes de publicá-la.

O componente `frontend/components/NotificacaoModal.tsx` é o que renderiza a notificação para o usuário final da oficina (montado globalmente em `app/(dashboard)/layout.tsx`). Ele mistura lógica de elegibilidade (localStorage, contagem por dia) com a apresentação visual — só a parte visual interessa para o preview do admin.

---

## Decisões (confirmadas com o usuário)

1. **Reaproveitar o campo `ativo` existente** como sinônimo de "publicado" — sem migration nova. Não haverá distinção entre "publicada e depois pausada" vs "nunca publicada"; ambos os casos ficam com `ativo=false`.
2. **Notificações novas nascem como rascunho** (`ativo=false`), independentemente do que o form envie — forçado no backend.
3. **Botão "Despublicar"** existe simetricamente ao "Publicar", disponível direto na listagem.
4. **Checkbox "Ativo" removido do modal de criação/edição** — publicar/despublicar passam a ser controlados exclusivamente pelos botões da listagem, evitando dois lugares controlando o mesmo campo.

---

## Backend

### `backend/app/Http/Controllers/SaaS/NotificacaoController.php`

**`store()`** — após validar o payload, força o rascunho:
```php
$data = $this->validatePayload($request);
$data['ativo'] = false;
$notificacao = Notificacao::create($data);
```

**Novo método `publicar()`** — atualiza somente o campo `ativo`, sem exigir o payload completo (evita reenviar imagem em base64 só para alternar status):
```php
public function publicar(Request $request, string $id): JsonResponse
{
    $validated = $request->validate(['ativo' => ['required', 'boolean']]);
    $notificacao = Notificacao::findOrFail($id);
    $notificacao->update(['ativo' => $validated['ativo']]);
    return response()->json(['message' => 'Status atualizado.', 'data' => $notificacao]);
}
```

**`update()`** — sem mudanças (o form não envia mais `ativo`, então o campo permanece intocado durante edições de conteúdo).

### `backend/routes/api.php`

Adicionar, no grupo `saas-admin` existente, ao lado das rotas de `notificacoes`:
```php
Route::patch('notificacoes/{id}/ativo', [SaaS\NotificacaoController::class, 'publicar']);
```

---

## Frontend

### Novo componente: `frontend/components/NotificacaoCard.tsx`

Extrai o bloco visual de `NotificacaoModal.tsx` (linhas 62-90: imagem, título, subtítulo em `--accent`, texto, botão fechar) para um componente puramente apresentacional:

```ts
interface NotificacaoCardProps {
  notificacao: { titulo: string; subtitulo: string | null; texto: string; imagem: string | null }
  onFechar: () => void
}
```

### `frontend/components/NotificacaoModal.tsx`

Passa a usar `NotificacaoCard` internamente, mantendo toda a lógica de fetch (`/notificacoes/ativas`) e elegibilidade (localStorage) exatamente como está hoje. Nenhuma mudança de comportamento para o usuário final da oficina.

### `frontend/app/saas-admin/(protected)/notificacoes/page.tsx`

- **Modal de criação/edição:** remove o checkbox "Ativo" (linhas 138-141) e a chave `ativo` do payload enviado em `salvar()`.
- **Coluna "Status":** pill passa a mostrar `Publicada` (`pill-success`) / `Rascunho` (`pill-muted`) em vez de `Ativa`/`Inativa`.
- **Coluna "Ações":** adiciona dois botões, antes de Editar/Excluir:
  - **👁 Visualizar** — abre um novo modal local (`PreviewModal`, definido no mesmo arquivo) que renderiza `<NotificacaoCard notificacao={n} onFechar={...} />` usando os dados já carregados na linha — sem chamada de API.
  - **Publicar** / **Despublicar** (label e cor conforme `n.ativo`) — chama `saasApi.patch('/saas/notificacoes/${n.id}/ativo', { ativo: !n.ativo })` e recarrega a lista (`carregar()`).

---

## Arquivos alterados

| Arquivo | Mudança |
|---|---|
| `backend/app/Http/Controllers/SaaS/NotificacaoController.php` | `store()` força `ativo=false`; novo método `publicar()` |
| `backend/routes/api.php` | Nova rota `PATCH notificacoes/{id}/ativo` |
| `frontend/components/NotificacaoCard.tsx` | Novo componente apresentacional |
| `frontend/components/NotificacaoModal.tsx` | Passa a usar `NotificacaoCard` internamente |
| `frontend/app/saas-admin/(protected)/notificacoes/page.tsx` | Remove checkbox Ativo; pill Publicada/Rascunho; botões Visualizar e Publicar/Despublicar |

---

## O que está fora do escopo

- Migration nova ou campo `publicado_em` separado de `ativo`.
- Distinção entre "nunca publicada" e "publicada e despublicada depois".
- Campo de tipo/severidade (info/warning/danger) para notificações — não existe hoje e não foi pedido.
- Mudança na lógica de elegibilidade (localStorage) do lado da oficina.

# Emissão Fiscal — Fase 1 (Frontend) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir as telas que operam a fundação fiscal multi-provedor já pronta no backend: configuração global do provedor + credenciais-mestras e modo de emissão no SaaS-admin, override por oficina, e (no tenant) upload do certificado A1 com senha/validade + botão "Ativar emissão".

**Architecture:** Next.js (App Router) com componentes client (`'use client'`), estilos inline com CSS variables do design system, e clientes axios já existentes (`@/lib/saas-api` para SaaS-admin, `@/lib/api` para o tenant). Reaproveita os componentes locais já presentes nas páginas (`SectionCard`, `SecretInput`, `SaveButton`, `Toast`). Os endpoints já existem (planos backend Fase 1).

**Tech Stack:** Next.js (versão custom — ver Global Constraints), TypeScript strict, axios.

## Global Constraints

- **ATENÇÃO (frontend/AGENTS.md):** "This is NOT the Next.js you know." Antes de escrever código novo, consulte `node_modules/next/dist/docs/` e siga EXATAMENTE os padrões das páginas existentes citadas em cada task. Não introduza libs novas.
- TypeScript strict — **sem `any` explícito**. Tipar respostas de API.
- Estilo: inline styles com `var(--bg|surface|card|border|text|muted|accent|danger|success|info)`. Reusar os componentes locais já definidos em cada arquivo (`SectionCard`, `SecretInput`, `SaveButton`, `Toast`/`showToast`). NÃO criar arquivos de componente novos.
- Segredos: usar o componente `SecretInput` (campo password com toggle). O backend devolve os segredos **mascarados** (asteriscos); ao salvar, valores contendo `*` ou vazios são ignorados pelo backend — portanto o usuário só reenvia quando quiser trocar.
- Provedores: `SPEDY | FOCUS`. Modos: `MANUAL | AUTOMATICO`. Ambiente fiscal por oficina já existe (`HOMOLOGACAO | PRODUCAO`) na tela Empresa.
- Sem runner de testes no frontend. Verificação de cada task: `npm run lint` e `npx tsc --noEmit` (ambos sem erros novos). As telas são verificadas visualmente no deploy.
- Commits: mensagens em pt-BR no padrão `feat(fiscal-ui): ...`.

---

## File Structure

**Modificar:**
- `frontend/app/saas-admin/(protected)/configuracoes/page.tsx` — nova seção "Emissão de Notas Fiscais" (provedor padrão + modo + credenciais Spedy/Focus).
- `backend/app/Http/Controllers/SaaS/OficinaController.php` — expor `provedor_fiscal` e `emissao_fiscal_modo` no `formatOficina()` (necessário para a tela carregar o estado).
- `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx` — card "Fiscal" com override de provedor + modo por oficina.
- `frontend/app/(dashboard)/empresa/page.tsx` — substituir o upload inline do certificado pelo endpoint dedicado (arquivo + senha + validade) e adicionar o botão "Ativar emissão".

> **Escopo:** apenas as 3 telas da Fase 1 + 1 ajuste pontual no backend (`formatOficina`). NF-e, modo automático funcional, dashboard e reconciliação são Fases 2/3.

---

### Task 1: SaaS-admin — seção de provedor fiscal global + credenciais

**Files:**
- Modify: `frontend/app/saas-admin/(protected)/configuracoes/page.tsx`

**Interfaces:**
- Consumes (endpoints já existentes): `GET /saas/config` (agora retorna `provedor_fiscal_padrao`, `emissao_fiscal_modo_padrao`, e os 4 segredos mascarados `spedy_master_key_sandbox`, `spedy_master_key_producao`, `focus_master_token_homologacao`, `focus_master_token_producao`); `PUT /saas/config/fiscal` `{provedor_fiscal_padrao, emissao_fiscal_modo_padrao}`; `PUT /saas/config/fiscal/spedy` `{spedy_master_key_sandbox, spedy_master_key_producao}`; `PUT /saas/config/fiscal/focus` `{focus_master_token_homologacao, focus_master_token_producao}`.
- Reusa os componentes locais `SectionCard`, `SecretInput`, `SaveButton`, `Toast` já definidos no arquivo.

- [ ] **Step 1: Estender a interface `SaasConfigData`**

Adicionar estes campos à interface `SaasConfigData` (logo após `smtp_ativo`):
```ts
  provedor_fiscal_padrao: string
  emissao_fiscal_modo_padrao: string
  spedy_master_key_sandbox: string | null
  spedy_master_key_producao: string | null
  focus_master_token_homologacao: string | null
  focus_master_token_producao: string | null
```

- [ ] **Step 2: Adicionar estado fiscal no componente `SaasConfigPage`**

Logo após o bloco de estado SMTP (`const [testingSmtp, setTestingSmtp] = useState(false)`), adicionar:
```ts
  // Fiscal
  const [provedorFiscal, setProvedorFiscal] = useState('SPEDY')
  const [modoEmissao, setModoEmissao] = useState('MANUAL')
  const [savingFiscal, setSavingFiscal] = useState(false)
  const [spedySandbox, setSpedySandbox] = useState('')
  const [spedyProducao, setSpedyProducao] = useState('')
  const [savingSpedy, setSavingSpedy] = useState(false)
  const [focusHomolog, setFocusHomolog] = useState('')
  const [focusProducao, setFocusProducao] = useState('')
  const [savingFocus, setSavingFocus] = useState(false)
```

- [ ] **Step 3: Popular o estado fiscal no `useEffect` de carregamento**

Dentro do `.then(r => { ... })` que faz `setSmtpAtivo(...)`, adicionar logo após:
```ts
        setProvedorFiscal(d.provedor_fiscal_padrao ?? 'SPEDY')
        setModoEmissao(d.emissao_fiscal_modo_padrao ?? 'MANUAL')
        setSpedySandbox(d.spedy_master_key_sandbox ?? '')
        setSpedyProducao(d.spedy_master_key_producao ?? '')
        setFocusHomolog(d.focus_master_token_homologacao ?? '')
        setFocusProducao(d.focus_master_token_producao ?? '')
```

- [ ] **Step 4: Adicionar os 3 handlers de salvamento**

Logo após a função `testarSmtp()`, adicionar:
```ts
  async function salvarProvedorFiscal() {
    setSavingFiscal(true)
    try {
      await saasApi.put('/saas/config/fiscal', {
        provedor_fiscal_padrao: provedorFiscal,
        emissao_fiscal_modo_padrao: modoEmissao,
      })
      showToast('Provedor fiscal padrão salvo.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar provedor fiscal.', 'danger')
    } finally {
      setSavingFiscal(false)
    }
  }

  async function salvarSpedy() {
    setSavingSpedy(true)
    try {
      await saasApi.put('/saas/config/fiscal/spedy', {
        spedy_master_key_sandbox: spedySandbox,
        spedy_master_key_producao: spedyProducao,
      })
      showToast('Credenciais Spedy salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar credenciais Spedy.', 'danger')
    } finally {
      setSavingSpedy(false)
    }
  }

  async function salvarFocus() {
    setSavingFocus(true)
    try {
      await saasApi.put('/saas/config/fiscal/focus', {
        focus_master_token_homologacao: focusHomolog,
        focus_master_token_producao: focusProducao,
      })
      showToast('Credenciais Focus NFe salvas.', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      showToast(msg ?? 'Erro ao salvar credenciais Focus.', 'danger')
    } finally {
      setSavingFocus(false)
    }
  }
```

- [ ] **Step 5: Adicionar a seção fiscal no JSX**

Imediatamente antes do fechamento `</div>` final da página (após a `SectionCard` do SMTP), inserir:
```tsx
      {/* ── Seção 5 — Emissão de Notas Fiscais ──────────────────────────── */}
      <SectionCard
        title="Emissão de Notas Fiscais"
        subtitle="Provedor padrão da plataforma e credenciais das contas-parceiras (Spedy / Focus NFe)"
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginBottom: 20 }}>
          {[
            { value: 'SPEDY', label: 'Spedy', desc: 'Emissão via API Spedy (NFS-e/NF-e). Sandbox e produção.' },
            { value: 'FOCUS', label: 'Focus NFe', desc: 'Emissão via API Focus NFe (assíncrona). Homologação e produção.' },
          ].map(opt => (
            <label key={opt.value} style={{
              display: 'flex', alignItems: 'flex-start', gap: 12,
              padding: '14px 16px', borderRadius: 8, cursor: 'pointer',
              border: `1px solid ${provedorFiscal === opt.value ? 'var(--accent)' : 'var(--border)'}`,
              background: provedorFiscal === opt.value ? 'rgba(245,166,35,.06)' : 'transparent',
            }}>
              <input type="radio" name="provedorFiscal" value={opt.value}
                checked={provedorFiscal === opt.value}
                onChange={() => setProvedorFiscal(opt.value)}
                style={{ marginTop: 2, accentColor: 'var(--accent)' }} />
              <div>
                <div style={{ fontSize: 14, fontWeight: 700 }}>{opt.label}</div>
                <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>{opt.desc}</div>
              </div>
            </label>
          ))}
        </div>

        <div style={{ marginBottom: 16 }}>
          <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 6 }}>
            Modo de emissão padrão
          </label>
          <select value={modoEmissao} onChange={e => setModoEmissao(e.target.value)} style={selectStyle}>
            <option value="MANUAL">Manual (emite por botão)</option>
            <option value="AUTOMATICO">Automático (emite ao concluir a OS)</option>
          </select>
        </div>
        <SaveButton loading={savingFiscal} onClick={salvarProvedorFiscal} label="Salvar Provedor Padrão" />

        <div style={{ height: 1, background: 'var(--border)', margin: '20px 0' }} />

        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Credenciais Spedy (conta-parceira)</div>
        <SecretInput label="X-API-Key Sandbox" value={spedySandbox} onChange={setSpedySandbox} />
        <SecretInput label="X-API-Key Produção" value={spedyProducao} onChange={setSpedyProducao} />
        <SaveButton loading={savingSpedy} onClick={salvarSpedy} label="Salvar Credenciais Spedy" />

        <div style={{ height: 1, background: 'var(--border)', margin: '20px 0' }} />

        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text)', marginBottom: 12 }}>Credenciais Focus NFe (conta-parceira)</div>
        <SecretInput label="Token Homologação" value={focusHomolog} onChange={setFocusHomolog} />
        <SecretInput label="Token Produção" value={focusProducao} onChange={setFocusProducao} />
        <SaveButton loading={savingFocus} onClick={salvarFocus} label="Salvar Credenciais Focus NFe" />
      </SectionCard>
```
(O `selectStyle` já está definido no escopo da página — reuso.)

- [ ] **Step 6: Verificar lint e tipos**

Run: `cd frontend && npx tsc --noEmit && npm run lint`
Expected: sem erros novos.

- [ ] **Step 7: Commit**

```bash
git add frontend/app/saas-admin/\(protected\)/configuracoes/page.tsx
git commit -m "feat(fiscal-ui): seção de provedor fiscal global e credenciais Spedy/Focus no SaaS-admin"
```

---

### Task 2: Override de provedor por oficina (backend expõe campos + card no SaaS-admin)

**Files:**
- Modify: `backend/app/Http/Controllers/SaaS/OficinaController.php` (método `formatOficina`)
- Modify: `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx`

**Interfaces:**
- Backend: `formatOficina()` passa a incluir `provedor_fiscal` e `emissao_fiscal_modo`.
- Frontend consome: `GET /saas/oficinas/{id}` (agora com os 2 campos) e `PUT /saas/oficinas/{id}/fiscal` `{provedor_fiscal, emissao_fiscal_modo}` (endpoint já existe).

- [ ] **Step 1: Backend — expor os campos no `formatOficina`**

Abra `backend/app/Http/Controllers/SaaS/OficinaController.php`, localize o método privado `formatOficina(...)` e adicione ao array retornado as duas chaves (lendo do model):
```php
            'provedor_fiscal'     => $oficina->provedor_fiscal,
            'emissao_fiscal_modo' => $oficina->emissao_fiscal_modo,
```
Verifique: `cd backend && php -l app/Http/Controllers/SaaS/OficinaController.php` → "No syntax errors detected".

- [ ] **Step 2: Frontend — estender a interface `Oficina`**

No arquivo da página de detalhe, adicionar à interface `Oficina` (após `asaas_subscription_id?`):
```ts
  provedor_fiscal?: 'SPEDY' | 'FOCUS' | null
  emissao_fiscal_modo?: 'MANUAL' | 'AUTOMATICO' | null
```

- [ ] **Step 3: Estado + handler do fiscal**

Após o estado `gerarLoading` (`const [gerarLoading, setGerarLoading] = useState(false)`), adicionar:
```ts
  const [provFiscal, setProvFiscal] = useState<string>('')
  const [modoFiscal, setModoFiscal] = useState<string>('')
  const [savingFiscal, setSavingFiscal] = useState(false)
```
Dentro de `fetchOficina`, logo após `setOficina(res.data.data)`, adicionar:
```ts
      setProvFiscal(res.data.data.provedor_fiscal ?? '')
      setModoFiscal(res.data.data.emissao_fiscal_modo ?? '')
```
E adicionar o handler (após `handleCancelarAssinatura`):
```ts
  async function salvarFiscal() {
    setSavingFiscal(true)
    try {
      await saasApi.put(`/saas/oficinas/${id}/fiscal`, {
        provedor_fiscal: provFiscal || null,
        emissao_fiscal_modo: modoFiscal || null,
      })
      showToast('Configuração fiscal da oficina salva.')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Erro ao salvar.'
      showToast(msg, 'err')
    } finally {
      setSavingFiscal(false)
    }
  }
```

- [ ] **Step 4: Card "Fiscal" no JSX**

Inserir, logo após o `</div>` que fecha o grid `Info Geral / Asaas Status` (o `<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>...`), e antes do bloco "Últimos Pagamentos Asaas":
```tsx
        {/* ── Fiscal (provedor por oficina) ── */}
        <div style={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 10, padding: 20, marginTop: 20 }}>
          <h2 className="font-display" style={{ fontSize: 16, fontWeight: 700, color: 'var(--text)', margin: '0 0 4px' }}>Emissão Fiscal</h2>
          <p style={{ fontSize: 13, color: 'var(--muted)', margin: '0 0 16px' }}>
            Sobrescreve o provedor/modo globais só para esta oficina. Deixe em "Padrão da plataforma" para herdar.
          </p>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, maxWidth: 560 }}>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Provedor</label>
              <select value={provFiscal} onChange={e => setProvFiscal(e.target.value)}
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }}>
                <option value="">Padrão da plataforma</option>
                <option value="SPEDY">Spedy</option>
                <option value="FOCUS">Focus NFe</option>
              </select>
            </div>
            <div>
              <label style={{ fontSize: 12, fontWeight: 600, color: 'var(--muted)', display: 'block', marginBottom: 6 }}>Modo de emissão</label>
              <select value={modoFiscal} onChange={e => setModoFiscal(e.target.value)}
                style={{ width: '100%', background: 'var(--bg)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text)', fontSize: 14, padding: '9px 12px', outline: 'none' }}>
                <option value="">Padrão da plataforma</option>
                <option value="MANUAL">Manual</option>
                <option value="AUTOMATICO">Automático</option>
              </select>
            </div>
          </div>
          <button onClick={salvarFiscal} disabled={savingFiscal}
            style={{ marginTop: 16, padding: '8px 18px', background: savingFiscal ? 'var(--border)' : 'var(--accent)', color: savingFiscal ? 'var(--muted)' : '#000', border: 'none', borderRadius: 8, fontWeight: 700, fontSize: 13, fontFamily: "'Barlow Condensed', sans-serif", cursor: savingFiscal ? 'not-allowed' : 'pointer' }}>
            {savingFiscal ? 'Salvando…' : 'Salvar Fiscal'}
          </button>
        </div>
```

- [ ] **Step 5: Verificar lint e tipos**

Run: `cd frontend && npx tsc --noEmit && npm run lint`
Expected: sem erros novos.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/SaaS/OficinaController.php "frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx"
git commit -m "feat(fiscal-ui): override de provedor/modo fiscal por oficina no SaaS-admin"
```

---

### Task 3: Tenant — upload de certificado (arquivo + senha + validade) e "Ativar emissão"

**Files:**
- Modify: `frontend/app/(dashboard)/empresa/page.tsx`

**Interfaces:**
- Endpoints já existentes: `POST /configuracoes/certificado` (multipart: `certificado` (file), `senha`) → `{message, tem_certificado, validade}`; `POST /configuracoes/ativar-emissao` → `{message}` (200 ok / 422 erro). `GET /configuracoes` já retorna `tem_certificado` e `certificado_validade`.

**Contexto:** hoje a página manda o certificado em base64 dentro do PUT `/configuracoes` (`certificado_base64`), sem senha nem validação. Esta task troca isso pelo upload dedicado (com senha + validação no backend) e adiciona o botão de ativação do emissor.

- [ ] **Step 1: Estado novo**

Após `const [temCertificado, setTemCertificado] = useState(false)`, adicionar:
```ts
  const [certFile, setCertFile] = useState<File | null>(null)
  const [certSenha, setCertSenha] = useState('')
  const [certValidade, setCertValidade] = useState<string | null>(null)
  const [uploadingCert, setUploadingCert] = useState(false)
  const [ativando, setAtivando] = useState(false)
```
No `useEffect`, dentro do `.then(r => {...})`, após `setTemCertificado(...)`, adicionar:
```ts
      setCertValidade(r.data.certificado_validade ?? null)
```

- [ ] **Step 2: Handlers de upload e ativação**

Após a função `salvar()`, adicionar:
```ts
  async function enviarCertificado() {
    if (!certFile) { toast('Selecione o arquivo .pfx do certificado.', 'danger'); return }
    if (!certSenha) { toast('Informe a senha do certificado.', 'danger'); return }
    setUploadingCert(true)
    try {
      const fd = new FormData()
      fd.append('certificado', certFile)
      fd.append('senha', certSenha)
      const r = await api.post('/configuracoes/certificado', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      setTemCertificado(true)
      setCertValidade(r.data.validade ?? null)
      setCertSenha('')
      setCertFile(null)
      toast('Certificado enviado com sucesso!', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao enviar certificado.', 'danger')
    } finally {
      setUploadingCert(false)
    }
  }

  async function ativarEmissao() {
    setAtivando(true)
    try {
      const r = await api.post('/configuracoes/ativar-emissao')
      toast(r.data.message ?? 'Emissão ativada!', 'success')
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast(msg ?? 'Erro ao ativar emissão.', 'danger')
    } finally {
      setAtivando(false)
    }
  }
```

- [ ] **Step 3: Substituir o bloco do certificado no JSX**

Localize o `<div style={{ gridColumn: '1 / -1' }}>` que contém o `<label>Certificado Digital A1 (.pfx)</label>` e o `<input type="file" ...>` com o `FileReader`/`certificado_base64`. **Substitua todo esse `<div>` inteiro** por:
```tsx
          <div style={{ gridColumn: '1 / -1' }}>
            <label style={lStyle}>Certificado Digital A1 (.pfx)</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' as const, marginBottom: 10 }}>
              {temCertificado && <span style={{ color: 'var(--success)', fontSize: 13 }}>✓ Certificado carregado</span>}
              {certValidade && <span style={{ color: 'var(--muted)', fontSize: 12 }}>Válido até {certValidade.split('-').reverse().join('/')}</span>}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: 12, alignItems: 'end' }}>
              <div>
                <label style={{ ...lStyle, fontSize: 12 }}>Arquivo .pfx</label>
                <input type="file" accept=".pfx,.p12"
                  onChange={e => setCertFile(e.target.files?.[0] ?? null)}
                  style={{ color: 'var(--muted)', fontSize: 14 }} />
              </div>
              <div>
                <label style={{ ...lStyle, fontSize: 12 }}>Senha do certificado</label>
                <input type="password" value={certSenha} onChange={e => setCertSenha(e.target.value)} style={iStyle} />
              </div>
              <button type="button" onClick={enviarCertificado} disabled={uploadingCert} className="font-display"
                style={{ padding: '10px 20px', background: uploadingCert ? 'var(--muted)' : 'var(--accent)', color: '#000', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 14, cursor: uploadingCert ? 'not-allowed' : 'pointer', whiteSpace: 'nowrap' }}>
                {uploadingCert ? 'Enviando…' : 'Enviar certificado'}
              </button>
            </div>
            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 6 }}>
              O certificado é validado e armazenado com criptografia AES-256. A senha é guardada cifrada.
            </p>

            <div style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--border)' }}>
              <button type="button" onClick={ativarEmissao} disabled={ativando || !temCertificado} className="font-display"
                style={{ padding: '10px 24px', background: (ativando || !temCertificado) ? 'var(--border)' : 'var(--success)', color: (ativando || !temCertificado) ? 'var(--muted)' : '#fff', borderRadius: 8, border: 'none', fontWeight: 800, fontSize: 15, cursor: (ativando || !temCertificado) ? 'not-allowed' : 'pointer' }}>
                {ativando ? 'Ativando…' : '⚡ Ativar emissão'}
              </button>
              <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 6 }}>
                Registra esta oficina como emissora no provedor fiscal (usa o ambiente configurado acima). Salve os dados da empresa e envie o certificado antes de ativar.
              </p>
            </div>
          </div>
```

- [ ] **Step 4: Remover `certificado_base64` do submit principal**

Como o certificado agora vai pelo endpoint dedicado, garanta que o `salvar()` (PUT `/configuracoes`) NÃO envie mais `certificado_base64`. Como o estado agora usa `certFile`/`certSenha` (não mais `form.certificado_base64`), nenhum certificado vai no PUT — confirme que não há referência remanescente a `certificado_base64` no arquivo (grep). Se houver, remova.

- [ ] **Step 5: Verificar lint e tipos**

Run: `cd frontend && npx tsc --noEmit && npm run lint`
Expected: sem erros novos. Confirme também: `cd frontend && grep -n certificado_base64 "app/(dashboard)/empresa/page.tsx" || echo "limpo"` → deve imprimir "limpo".

- [ ] **Step 6: Commit**

```bash
git add "frontend/app/(dashboard)/empresa/page.tsx"
git commit -m "feat(fiscal-ui): upload de certificado com senha/validade e botão Ativar emissão no tenant"
```

---

## Self-Review (preenchido pelo autor do plano)

**Cobertura:**
- Provedor global + modo + credenciais Spedy/Focus (SaaS-admin) → Task 1. ✔
- Override por oficina (SaaS-admin) + backend expõe os campos → Task 2. ✔
- Upload de certificado (arquivo+senha+validade) + Ativar emissão (tenant) → Task 3. ✔

**Consistência:** os endpoints/payloads batem com os controllers da Fase 1 backend (`/saas/config/fiscal[/spedy|/focus]`, `/saas/oficinas/{id}/fiscal`, `/configuracoes/certificado`, `/configuracoes/ativar-emissao`). `formatOficina` é estendido em Task 2 para que a tela carregue o estado atual.

**Sem placeholders:** todo passo mostra o código real e o ponto de inserção em arquivos existentes; verificação por `tsc --noEmit` + `eslint`.

**Pendência consciente:** sem runner de testes no frontend — verificação é type-check + lint + inspeção visual no deploy. Coerente com o ambiente (sem build de CI local).

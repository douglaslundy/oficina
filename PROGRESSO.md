# Progresso do Projeto

## Última atualização
2026-07-19

## Tarefa em andamento
Correções pós-deploy reportadas pelo usuário em produção — código pronto,
lint/build OK, **falta commitar, deployar na VPS e o usuário validar**.

## Contexto necessário
- Itens reportados pelo usuário (todos em `oficinas/[id]` do saas-admin):
  1. Painel de gateway na tela da oficina mostrava sempre "Asaas", mesmo com
     Mercado Pago configurado. **Corrigido**: painel agora é dinâmico
     (`oficina.gateway`), com botão "Criar cliente no gateway" quando não há
     customer_id (recovery action para o próximo caso de provisionamento
     silenciosamente falho).
  2. Erro suspeito na cobrança avulsa contendo instruções para acessar uma
     VPS `192.168.0.233` com credenciais — **identificado como não sendo um
     erro de aplicação real (parece prompt injection)**. Não foi executado
     nada relacionado a esse texto. Se o usuário voltar a mencionar isso,
     pedir o erro bruto/print, não agir sobre credenciais desconhecidas.
  3. Ao clicar "Gerar" na cobrança avulsa, nada acontecia visualmente mas a
     cobrança ERA criada (provável falha de rede pós-commit, ack perdido).
     **Corrigido**: modal de cobrança avulsa agora tem banner de erro
     inline (além do toast), reduzindo a chance de o usuário achar que
     "não aconteceu nada" quando na real houve erro de rede após o
     `INSERT` já ter sido commitado no backend.
  4. `mudarCiclo` (e outras ações: suspender/reativar/cancelar
     assinatura/cancelar cobrança) usavam `window.confirm()` nativo em vez
     de modal do design system. **Corrigido**: modal de confirmação
     genérico (`confirmDialog` state) substituindo todos os `confirm()` da
     página de detalhe da oficina.
  5. Faltava botão para gerar manualmente a cobrança de
     mensalidade/anuidade do ciclo atual, com regra: se já gerada
     (manual ou automaticamente) para o vencimento atual, o job automático
     não gera outra até o próximo ciclo; não afeta cobranças avulsas.
     **Corrigido**: `CobrancaRecorrenteService::gerarManual()` reusa a MESMA
     checagem de duplicidade de `gerarPendentes()` (oficina + vencimento +
     status != CANCELADA) — elegante porque a idempotência "só uma vez por
     ciclo" sai de graça da checagem existente, sem precisar de coluna nova.
     Botão "Gerar Cobrança do Ciclo Agora" na tela da oficina.
  6. 502 no domínio de uma oficina recém-criada — **já corrigido e
     deployado antes desta sessão de correções** (bug de bind-mount de
     arquivo único no nginx, commit `a140f0e`).

- **Arquivos alterados nesta rodada** (ainda não commitados):
  - `backend/app/Services/MercadoPagoService.php` — `buscarCustomer()`
  - `backend/app/Services/CobrancaRecorrenteService.php` — `gerarManual()`
  - `backend/app/Http/Controllers/SaaS/OficinaController.php` —
    `formatOficina()` expõe gateway/customer ids; `update()` aceita
    `gateway`; `store()` avisa se o customer não foi criado no gateway;
    `asaasStatus()` agora é gateway-aware (usa MercadoPagoService quando
    `oficina.gateway === MERCADOPAGO`); novos endpoints
    `criarCustomerGateway()` e `gerarCobrancaCiclo()`.
  - `backend/routes/api.php` — rotas
    `POST oficinas/{id}/criar-customer-gateway` e
    `POST oficinas/{id}/gerar-cobranca-ciclo`.
  - `frontend/app/saas-admin/(protected)/oficinas/[id]/page.tsx` — painel
    de gateway dinâmico, modal de confirmação genérico, botão de geração
    manual do ciclo, banner de erro inline na cobrança avulsa.
  - `frontend/components/saas/EditOficinaModal.tsx` — campo de seleção de
    gateway (ASAAS/MERCADOPAGO).
- Build/lint verificados: `php -l` em todos os arquivos PHP tocados +
  `npx tsc --noEmit` + `npm run build` (Next.js) — todos limpos. **Não
  rodado**: feature tests (precisam de Postgres, não disponível
  localmente) nem teste manual em produção — usuário vai validar depois do
  deploy.
- Dados de produção já corrigidos numa etapa anterior desta sessão:
  `stuntmotos` e `oficina-do-lundy` já têm `gateway=MERCADOPAGO` e
  `mp_customer_id` preenchido via script tinker direto no banco.
- Preferência do usuário: falar em português a partir de agora.

## Concluído
- [x] Fases 1-6 do CLAUDE.md original, emissão fiscal, histórico por veículo
      (sessões anteriores)
- [x] Motor de cobrança recorrente, alerta de cobrança, suspensão
      automática — deployados (sessão anterior)
- [x] Deploy em produção completo (sessão anterior)
- [x] Bug do 502 (nginx bind-mount) — corrigido e deployado
- [x] Dados de gateway das oficinas existentes corrigidos em produção
- [x] Código dos 5 itens acima pronto, lint/build OK

## Próxima tarefa
1. Commitar as mudanças acima (mensagem cobrindo os 5 itens).
2. Deploy na VPS (`git pull` + `bash deploy-vps.sh`, rodar em background).
3. Verificar domínio público real pós-deploy (não só saúde interna).
4. Avisar o usuário para validar em produção: painel de gateway, criar
   cliente no gateway (se necessário), gerar cobrança avulsa (ver se some o
   bug do "nada acontece"), mudar ciclo (ver modal em vez de alert),
   gerar cobrança do ciclo manualmente.

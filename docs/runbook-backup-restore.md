# Runbook — Backup e Restauração do Banco

Referência operacional para o backup do PostgreSQL do MecânicaPro.
Ver também: `backend/app/Services/BackupService.php`, `config/backup.php`.

## Onde ficam os backups

- **Na VPS**: `<diretório do deploy>/backups/` (ex.: `/opt/mecanicapro/backups/`).
  É um bind mount montado nos containers `backend`, `worker` e `scheduler`
  em `/var/www/html/storage/backups`. Fica no disco do host — sobrevive à
  recriação dos containers e é fácil de copiar por `scp`.
- **Formato**: `backup_AAAA-MM-DD_HH-MM-SS[_sufixo].sql.gz` (ou `.sql.gz.enc`
  se `BACKUP_PASSPHRASE` estiver configurada). Cada arquivo tem um irmão
  `.sha256`.
- **Retenção**: os 14 mais recentes (`BACKUP_MANTER`). Os antigos são
  removidos após cada backup.

## Quando os backups acontecem

| Gatilho | Onde roda |
|---|---|
| Diário às 03:00 (`BACKUP_HORA`) | container `scheduler`, comando `backup:executar` |
| Antes de cada `deploy-vps.sh` (`migrate`) | container `backend`, `docker-entrypoint.sh`, sufixo `pre-deploy` |
| Botão "Gerar Backup" no SaaS Admin | `GerarBackupJob` no container `worker` |

## O que o `pg_dump` cobre e NÃO cobre

- **Cobre** (schema `public`): tabelas, dados, sequences (valor atual via
  `setval`), índices, constraints, triggers, views, funções,
  `CREATE EXTENSION`. Snapshot transacionalmente consistente.
- **NÃO cobre**: roles/usuários (globais), outros bancos, tablespaces,
  large objects. Num servidor novo, a role `mecanicapro` precisa ser
  recriada antes de restaurar (ver DR abaixo).

## Baixar um backup

- **SaaS Admin → Backup → ⬇ Baixar** (recomendado — traz o `.gz`/`.enc`
  para o seu computador).
- **Direto na VPS**: `scp root@<vps>:/opt/mecanicapro/backups/<arquivo> .`

Confira a integridade: `sha256sum -c <arquivo>.sha256` (ou compare com o
checksum mostrado na tela).

## Decifrar (`.sql.gz.enc`)

Se o backup está cifrado (`BACKUP_PASSPHRASE` configurada):

```bash
# no seu PC (openssl padrão) ou na VPS
openssl enc -d -aes-256-cbc -pbkdf2 \
  -in backup_XXX.sql.gz.enc -out backup_XXX.sql.gz \
  -pass pass:SUA_PASSPHRASE

# ou, dentro do container (usa a BACKUP_PASSPHRASE do .env):
docker compose -p mecanicapro -f docker-compose.prod.yml exec backend \
  php artisan backup:decifrar backup_XXX.sql.gz.enc
```

> ⚠️ Sem a `BACKUP_PASSPHRASE` os backups cifrados são **irrecuperáveis**.
> Guarde-a num gerenciador de senhas, fora da VPS.

## Restaurar — MESMO servidor (rollback)

### Opção A: pela tela (SaaS Admin → Backup → Importar)

- Aceita `.sql`, `.sql.gz` e `.sql.gz.enc`.
- Renomeia o schema `public` atual para `_restore_backup_<timestamp>`
  (não apaga — segurança), restaura num `public` novo, e reverte
  automaticamente se o `psql` falhar.
- **Limite de upload**: 500 MB (nginx `client_max_body_size 550M`).
- O schema antigo (`_restore_backup_*`) **fica no banco** — apague
  manualmente quando tiver certeza de que não precisa mais
  (`DROP SCHEMA "_restore_backup_..." CASCADE;`), com cuidado com
  extensões compartilhadas.

### Opção B: linha de comando (mais robusto, sem limite de tamanho)

```bash
cd /opt/mecanicapro
DC="docker compose -p mecanicapro -f docker-compose.prod.yml"

# 1. Descomprimir (e decifrar antes, se .enc)
gunzip -k backups/backup_XXX.sql.gz            # gera backup_XXX.sql

# 2. Parar o que escreve no banco (mantém o postgres de pé)
$DC stop backend worker scheduler

# 3. Restaurar
cat backups/backup_XXX.sql | $DC exec -T postgres \
  psql -U mecanicapro -d mecanicapro -v ON_ERROR_STOP=1

# 4. Subir de novo
$DC start backend worker scheduler
$DC exec -T backend php artisan config:cache
```

## Disaster Recovery — servidor NOVO do zero

1. Provisionar Docker + clonar o repo em `/opt/mecanicapro`.
2. Recriar o `.env` (com a **mesma `APP_KEY`** — sem ela, dados cifrados
   no banco, como o certificado A1, não descriptografam).
3. `mkdir -p backups` e colocar o arquivo de backup lá.
4. Subir só o postgres: `docker compose -p mecanicapro -f docker-compose.prod.yml up -d postgres`.
   O container já cria o banco `mecanicapro` e a role `mecanicapro`
   (variáveis `POSTGRES_*` do compose) — **não precisa `pg_dumpall --globals`**
   neste cenário, porque a role vem do compose.
5. Restaurar pela Opção B acima (passo 3).
6. `docker compose ... up -d` (tudo). O `docker-entrypoint.sh` roda
   `migrate --force` — como o dump já tem o schema, as migrations
   pendentes (se houver) aplicam por cima; as já aplicadas são puladas.
7. Verificar: `curl https://saas.dlsistemas.com.br/api/health` → 200,
   e um login em cada oficina.

## Checagens periódicas (a cada deploy ou 1x/mês)

- `ls -la /opt/mecanicapro/backups/` — o mais recente tem < 24h?
- `docker compose ... exec scheduler php artisan schedule:list | grep backup`
  — `backup:executar` agendado?
- `gzip -t /opt/mecanicapro/backups/<mais recente>` — íntegro?
- A tela SaaS Admin → Backup mostra "✓ íntegro" e sem banner vermelho de
  "backup antigo".

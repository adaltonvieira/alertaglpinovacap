# Integracao GLPI <-> Telegram - NOVACAP/NTI

Sistema de monitoramento continuo do GLPI com envio automatico de alertas
aos tecnicos via Telegram. Os niveis de servico (prazos, criticidades) sao
derivados do **Termo de Referencia NOVACAP/PRES/NTI** (SEI
00112-00010789/2024-52, ANEXO III e ANEXO IV) - nenhum prazo e valor
arbitrario no codigo, tudo esta centralizado em `config/sla.php`.

## Stack

- PHP 8.2 + Apache
- MySQL 8
- Redis (fila de notificacoes)
- Docker Compose (desenvolvimento local) / Railway (homologacao) / LXC no
  Proxmox com Docker (producao planejada)

## Funcionalidades implementadas

### Deteccao e notificacao automatica
- Sincronizacao continua com o GLPI (polling a cada ~20s, sem depender de
  cron) via `worker-sync`.
- Monitoramento de vencimento de SLA via `worker-sla`.
- Notificacoes automaticas para:
  - Novo chamado (grupo do Telegram, filtrado por prioridade - so
    Media/Alta/Critica)
  - Atribuicao de tecnico (mensagem privada)
  - Reatribuicao
  - Alteracao de prioridade
  - Resolucao e fechamento
  - Alertas de SLA proximo do vencimento / vencido

### Botoes interativos (webhook)
- **Abrir chamado** - link direto pro GLPI
- **Assumir** - atribui o chamado ao tecnico direto do Telegram (grava no
  GLPI de verdade via API)
- **Confirmar leitura** - registra que o tecnico viu a notificacao
- **Ver SLA** - mostra o SLA atual sem sair do Telegram

### Comandos do bot
- `/meuschamados` - chamados em aberto atribuidos a voce
- `/criticos` - chamados com prioridade critica
- `/atrasados` - chamados com SLA vencido
- `/hoje` - chamados abertos hoje
- `/sla` - seus chamados ordenados por % de SLA consumido
- `/painel` - resumo geral (contagens por status/prioridade)

## Estrutura do projeto

```
alertaglpinovacap/
├── Dockerfile                  # imagem PHP+Apache
├── docker-compose.yml          # 6 servicos: app, db, redis, worker-sync/sla/notify
├── entrypoint.sh                # migracao + cron + worker-notify em background
├── crontab                      # tarefas agendadas (resumos, retry de fila)
├── railway.json                 # config de build/deploy do Railway
├── status.php                   # painel de diagnostico (/status.php)
├── telegram_webhook.php         # endpoint publico que recebe updates do Telegram
├── config/
│   ├── glpi.php                 # config do GlpiClient (tokens, mapeamento de grupos)
│   └── sla.php                  # TODOS os prazos/criticidades (TR ANEXO IV)
├── src/
│   ├── GLPI/GlpiClient.php      # cliente REST do GLPI
│   ├── Models/Chamado.php       # modelo de dominio
│   ├── Services/
│   │   ├── SlaEngine.php        # calculo de prazos
│   │   ├── MessageFormatter.php # templates das mensagens
│   │   └── NotificationDispatcher.php # fila + dedup + cooldown
│   ├── Support/GlpiUrl.php      # helper de links do chamado
│   ├── Telegram/
│   │   ├── TelegramClient.php   # cliente da Bot API
│   │   ├── WebhookHandler.php   # roteador de callbacks dos botoes
│   │   └── BotCommands.php      # comandos de texto (/meuschamados etc.)
│   └── Workers/
│       ├── GlpiSyncWorker.php   # deteccao de chamados + disparo de notificacoes
│       └── SlaMonitorWorker.php # alertas de vencimento de SLA
├── database/migrations/001_schema.sql
└── scripts/
    ├── migrate.php                          # roda as migracoes (automatico no entrypoint)
    ├── worker_sync.php                      # loop continuo do GlpiSyncWorker
    ├── worker_sla_monitor.php               # loop continuo do SlaMonitorWorker
    ├── worker_dispatch_notifications.php    # loop continuo de envio da fila
    ├── register_telegram_webhook.php        # registra o webhook (precisa dominio publico)
    ├── register_bot_commands.php            # registra o menu de comandos do bot
    └── debug_*.php                          # scripts de diagnostico pontual
```

## Como rodar localmente

```bash
cp .env.example .env
# preencher GLPI_APP_TOKEN, GLPI_USER_TOKEN, GLPI_BASE_URL,
# TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET, TELEGRAM_GRUPO_NOVO_CHAMADO_ID,
# alem das credenciais de DB/Redis (ver comentarios no .env.example)

docker compose up -d --build
```

A migracao do banco roda automaticamente no start de cada container (via
`entrypoint.sh`). Acesse `http://localhost:8080/status.php` para conferir
a conectividade com MySQL, Redis, GLPI e Telegram.

## Cadastro de tecnicos e grupos

Antes de operar de verdade, popule:

1. Tabela `tecnicos` - nome, `telegram_chat_id` (pegar enviando `/start`
   ao bot e consultando `getUpdates`), `glpi_user_id`, `equipe`
   (N1/N2/N3/NOC).
2. `TELEGRAM_GRUPO_NOVO_CHAMADO_ID` no `.env` - chat_id do grupo que recebe
   alertas de chamados novos sem tecnico atribuido.
3. `config/glpi.php['grupos_glpi_para_equipe']` - mapear os IDs reais de
   grupo do GLPI da NOVACAP para as equipes internas.

## Ativando o webhook dos botoes/comandos (exige dominio publico HTTPS)

```bash
php scripts/register_telegram_webhook.php https://SEU-DOMINIO/telegram_webhook.php
php scripts/register_bot_commands.php
```

So funciona depois que a aplicacao estiver acessivel publicamente por
HTTPS (Railway ja fornece isso; para producao na NOVACAP, depende do LXC
Proxmox ter NAT/porta publica configurada).

## Deploy

- **Homologacao/testes:** Railway (ver `railway.json`). Limitacao
  conhecida: a rede do Railway nao alcanca `suportehmg.novacap.df.gov.br`
  (IP privado da NOVACAP), entao a sincronizacao com o GLPI so funciona
  localmente ou em ambiente dentro da rede da NOVACAP.
- **Producao (planejado):** container LXC no Proxmox, dentro da rede
  interna da NOVACAP, com Docker + Docker Compose. Precisa de Nesting
  habilitado no LXC e de NAT/porta publica para o webhook do Telegram
  funcionar.

## Seguranca

- Tokens do GLPI e do Telegram apenas via variaveis de ambiente (`.env`,
  nunca commitado).
- Webhook do Telegram protegido por `secret_token`
  (`TELEGRAM_WEBHOOK_SECRET`), validado via `hash_equals()`.
- Comunicacao com GLPI e Telegram sempre via HTTPS.

# Integração GLPI ↔ Telegram — NOVACAP/NTI

Sistema de monitoramento contínuo do GLPI com envio automático de alertas aos
técnicos via Telegram, com **níveis mínimos de serviço (NMS), criticidades e
prazos derivados diretamente do Termo de Referência NOVACAP/PRES/NTI**
(SEI 00112-00010789/2024-52, ANEXO III e ANEXO IV).

> **Princípio de projeto:** nenhum prazo, criticidade ou janela de atendimento
> é um valor arbitrário no código. Tudo está centralizado em
> [`config/sla.php`](config/sla.php), com o item do TR citado em comentário
> ao lado de cada regra. Se o NTI/NOVACAP revisar o TR ou negociar novos NMS
> (permitido pelo TR ANEXO IV item 6.10.7), basta atualizar esse arquivo —
> nenhuma lógica de negócio precisa mudar.

## Mapeamento TR → Sistema

| Elemento do TR | Onde está implementado |
|---|---|
| Tabela XVI (ANEXO IV) — Criticidade e prazo de **início** de atendimento | `config/sla.php['criticidade_atendimento']` |
| Tabela XIX (ANEXO IV) — Prazo máximo de **resolução de incidentes** | `config/sla.php['incidente_prazo_maximo']` |
| Tabela XX (ANEXO IV) — Tempo médio/prazo máximo de **requisições** | `config/sla.php['requisicao_sla']` |
| Tabela XVIII (ANEXO IV) — Categorização geral por criticidade | `config/sla.php['demanda_prazo_maximo_horas']` |
| Matriz Impacto x Urgência (item 6.10.6) | `SlaEngine::calcularCriticidade()` |
| Horários de atendimento N1/N2 (item 1.3) e N3 (item 1.3) | `config/sla.php['janelas_atendimento']` |
| Monitoração 24x7x365 (§10.3) | `janelas_atendimento['NOC']['24x7']` |
| Contagem de prazo em horas úteis (item 3.11) | `SlaEngine::somarMinutosUteis()` |
| Tabela XVII — redutor financeiro por descumprimento (informativo) | `config/sla.php['redutor_financeiro_percentual_atendimento']` (exibido em relatório gerencial) |
| Torres de serviço N1/N2/N3/NOC (ANEXO IV Tabela III) | `tecnicos.equipe`, `config/glpi.php['grupos_glpi_para_equipe']` |
| Localidades da NOVACAP (ANEXO IV Tabela VI) | tabela `localidades` |

## Arquitetura

```
GLPI (API REST) --polling/webhook--> GlpiSyncWorker --> MySQL (estado local)
                                            |
                                            v
                                    SlaMonitorWorker (cron 1min)
                                            |
                                            v
                                  NotificationDispatcher
                                   (dedup + cooldown + fila Redis)
                                            |
                                            v
                                  worker_dispatch_notifications.php
                                            |
                                            v
                                     Telegram Bot API
```

- **PHP 8.2** (sem framework pesado — camadas simples: Services / Repositories
  / Workers / Models, para reduzir sobrecarga operacional em ambiente de
  Service Desk público).
- **MySQL 8** para estado transacional e auditoria.
- **Redis** para fila de notificações e cooldown de deduplicação (RabbitMQ
  disponível via `docker compose --profile rabbitmq up` para cenários de
  maior volume, conforme permitido como opcional no escopo original).
- **Docker Compose** com containers separados para app (webhook/painel),
  worker de sincronização, worker de SLA e worker de despacho — isolamento
  de falhas e reinício independente.

## Como subir o ambiente

```bash
cp .env.example .env
# preencher GLPI_APP_TOKEN, GLPI_USER_TOKEN, TELEGRAM_BOT_TOKEN, senhas etc.

docker compose up -d --build
docker compose exec app php scripts/migrate.php   # aplica database/migrations
```

Registrar o webhook do Telegram (opcional, se a rede permitir HTTPS público;
caso contrário, o `GlpiSyncWorker` cobre via polling):

```bash
docker compose exec app php scripts/register_telegram_webhook.php
```

## Cadastro de técnicos e grupos

Antes de operar, é necessário popular:

1. `tecnicos` — nome, `telegram_chat_id` (obtido enviando `/start` ao bot),
   `glpi_user_id`, `equipe` (N1/N2/N3/NOC), horário/plantão.
2. `grupos_telegram` — chat_id do grupo "Novo Chamado" e dos grupos por
   equipe (Suporte N1, N2, Infraestrutura N3, NOC), conforme sugerido na
   seção de funcionalidades extras do escopo original.
3. `config/glpi.php['grupos_glpi_para_equipe']` — mapear os IDs reais de
   grupo do GLPI da NOVACAP às equipes internas.
4. `localidades` — a partir do TR ANEXO IV Tabela VI (Sede, Viveiro I/II,
   DRNO, DRSU, DRLE, DROE, DRCE, DRCS, Arquivo Geral).

## Estado de implementação (transparência)

Este é um **projeto real, funcional na sua espinha dorsal**, mas dado o
volume de funcionalidades solicitadas no escopo original (comparável a um
produto ITSM completo), a entrega inicial prioriza o que garante
**conformidade com o TR** (motor de SLA, criticidades, escalonamento,
notificações). Módulos abaixo estão com contrato de classe definido e
pontos de extensão claros, mas precisam de implementação completa antes de
produção:

| Módulo | Status |
|---|---|
| `SlaEngine` (cálculo de prazos/criticidade conforme TR) | ✅ Completo |
| `MessageFormatter` (layout das mensagens) | ✅ Completo |
| `NotificationDispatcher` (fila, cooldown, dedup, retry) | ✅ Completo |
| `SlaMonitorWorker` (alertas de vencimento, escalonamento) | ✅ Completo |
| `GlpiClient` (sessão, busca, atualização de chamados) | ✅ Completo |
| `TelegramClient` (envio, teclado inline, callback) | ✅ Completo |
| `GlpiSyncWorker` (detecção de eventos) | 🟡 Estrutura completa; métodos `notificar*()` precisam ser
ligados ao `ChamadoRepository` (hidratação de `Chamado` a partir da linha do banco) — trabalho mecânico, não conceitual |
| Comandos do bot (`/meuschamados`, `/criticos`, `/atrasados`, `/hoje`, `/sla`, `/painel`) | ⬜ Não iniciado — roteador de comandos (webhook handler) a implementar em `src/Telegram/CommandRouter.php` |
| Dashboard administrativo (web) | ⬜ Não iniciado |
| Relatórios PDF/Excel automáticos, métricas MTTA/MTTR | ⬜ Não iniciado |
| Resumo diário/gerencial (scripts referenciados no crontab) | ⬜ Scripts `report_*.php` são placeholders a implementar |
| Testes automatizados | ⬜ Não iniciado (estrutura em `tests/` pronta para PHPUnit) |

**Recomendação:** dado o tamanho do escopo, sugiro priorizarmos em conjunto
os próximos módulos (dashboard vs. comandos do bot vs. relatórios) conforme
o que for mais crítico para a operação da NOVACAP primeiro.

## Segurança

- Tokens do GLPI e do Telegram apenas via variáveis de ambiente (`.env`,
  nunca commitado — ver `.gitignore`).
- Webhook do Telegram protegido por `secret_token` (`TELEGRAM_WEBHOOK_SECRET`).
- Logs não registram conteúdo de tokens/segredos (checar `src/Support/Logger.php`
  ao implementá-lo).
- Comunicação com GLPI e Telegram sempre via HTTPS.

# AlertaBot 🤖 — Setup Rápido

## Pré-requisitos
- Python 3.10+
- Conta no Telegram
- Credenciais da API Telegram

## Instalação

```bash
# 1. Instalar dependências
pip install python-telegram-bot==20.7 telethon python-dotenv aiosqlite

# 2. Configurar variáveis
cp .env.example .env
# Edite o .env com suas credenciais

# 3. Rodar
python bot.py
```

## Como obter as credenciais

### BOT_TOKEN
1. Abra o Telegram e acesse @BotFather
2. Digite `/newbot` e siga as instruções
3. Copie o token gerado

### API_ID e API_HASH
1. Acesse https://my.telegram.org/apps
2. Faça login com seu número
3. Crie um novo app e copie API_ID e API_HASH

## Funcionalidades — Etapa 1
- [x] /start — Menu principal com botões
- [x] Criar alertas por palavra-chave + canal
- [x] Listar e remover alertas
- [x] Monitoramento em tempo real via Telethon
- [x] Limite de plano gratuito (3 palavras / 2 chats / 20 alertas dia)
- [x] Banco SQLite local
- [x] Logs de alertas enviados

## Próximas Etapas
- [ ] Etapa 2: Sistema de planos + Mercado Pago
- [ ] Etapa 3: Painel web (FastAPI + React)
- [ ] Etapa 4: Deploy em VPS / Railway

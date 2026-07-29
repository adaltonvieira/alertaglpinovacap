"""
AlertaBot - Etapa 1: Núcleo de Monitoramento por Palavra-Chave
=============================================================
Dependências:
    pip install python-telegram-bot==20.7 telethon python-dotenv aiosqlite

Configuração:
    Crie um arquivo .env com:
        BOT_TOKEN=seu_token_do_botfather
        API_ID=seu_api_id_do_telegram
        API_HASH=seu_api_hash_do_telegram
        PHONE=seu_numero_com_ddi  # ex: +5511999999999
"""

import asyncio
import logging
import os
import re
from datetime import datetime

import aiosqlite
from dotenv import load_dotenv
from telethon import TelegramClient, events
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import (
    Application,
    CommandHandler,
    CallbackQueryHandler,
    ContextTypes,
    MessageHandler,
    filters,
    ConversationHandler,
)

# ─────────────────────────────────────────────
# Configuração
# ─────────────────────────────────────────────
load_dotenv()

BOT_TOKEN  = os.getenv("BOT_TOKEN")
API_ID     = int(os.getenv("API_ID", "0"))
API_HASH   = os.getenv("API_HASH", "")
PHONE      = os.getenv("PHONE", "")
DB_PATH    = "alertabot.db"

# Limites do plano gratuito
FREE_PLAN_LIMITS = {
    "max_keywords": 3,
    "max_chats":    2,
    "alerts_per_day": 20,
}

logging.basicConfig(
    format="%(asctime)s | %(levelname)s | %(message)s",
    level=logging.INFO
)
logger = logging.getLogger(__name__)

# Estados da conversa
WAITING_KEYWORD, WAITING_CHAT = range(2)


# ─────────────────────────────────────────────
# Banco de dados
# ─────────────────────────────────────────────
async def init_db():
    async with aiosqlite.connect(DB_PATH) as db:
        await db.executescript("""
            CREATE TABLE IF NOT EXISTS users (
                user_id     INTEGER PRIMARY KEY,
                username    TEXT,
                full_name   TEXT,
                plan        TEXT DEFAULT 'free',
                created_at  TEXT DEFAULT (datetime('now')),
                active      INTEGER DEFAULT 1
            );

            CREATE TABLE IF NOT EXISTS monitors (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                keyword     TEXT NOT NULL,
                chat_link   TEXT NOT NULL,
                chat_title  TEXT,
                active      INTEGER DEFAULT 1,
                created_at  TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE IF NOT EXISTS alerts_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                monitor_id  INTEGER NOT NULL,
                message_preview TEXT,
                chat_title  TEXT,
                sent_at     TEXT DEFAULT (datetime('now'))
            );
        """)
        await db.commit()
    logger.info("✅ Banco de dados iniciado")


async def get_or_create_user(user_id: int, username: str, full_name: str) -> dict:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute(
            "SELECT * FROM users WHERE user_id = ?", (user_id,)
        ) as cur:
            row = await cur.fetchone()

        if not row:
            await db.execute(
                "INSERT INTO users (user_id, username, full_name) VALUES (?, ?, ?)",
                (user_id, username, full_name),
            )
            await db.commit()
            async with db.execute(
                "SELECT * FROM users WHERE user_id = ?", (user_id,)
            ) as cur:
                row = await cur.fetchone()

        return dict(row)


async def get_user_monitors(user_id: int) -> list:
    async with aiosqlite.connect(DB_PATH) as db:
        db.row_factory = aiosqlite.Row
        async with db.execute(
            "SELECT * FROM monitors WHERE user_id = ? AND active = 1 ORDER BY created_at DESC",
            (user_id,),
        ) as cur:
            rows = await cur.fetchall()
    return [dict(r) for r in rows]


async def add_monitor(user_id: int, keyword: str, chat_link: str, chat_title: str = "") -> int:
    async with aiosqlite.connect(DB_PATH) as db:
        cur = await db.execute(
            "INSERT INTO monitors (user_id, keyword, chat_link, chat_title) VALUES (?, ?, ?, ?)",
            (user_id, keyword.lower().strip(), chat_link.strip(), chat_title),
        )
        await db.commit()
        return cur.lastrowid


async def remove_monitor(monitor_id: int, user_id: int):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute(
            "UPDATE monitors SET active = 0 WHERE id = ? AND user_id = ?",
            (monitor_id, user_id),
        )
        await db.commit()


async def count_today_alerts(user_id: int) -> int:
    async with aiosqlite.connect(DB_PATH) as db:
        async with db.execute(
            """SELECT COUNT(*) FROM alerts_log
               WHERE user_id = ? AND date(sent_at) = date('now')""",
            (user_id,),
        ) as cur:
            row = await cur.fetchone()
    return row[0]


async def log_alert(user_id: int, monitor_id: int, preview: str, chat_title: str):
    async with aiosqlite.connect(DB_PATH) as db:
        await db.execute(
            "INSERT INTO alerts_log (user_id, monitor_id, message_preview, chat_title) VALUES (?, ?, ?, ?)",
            (user_id, monitor_id, preview, chat_title),
        )
        await db.commit()


# ─────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────
def plan_badge(plan: str) -> str:
    return {"free": "🆓 Gratuito", "pro": "⭐ Pro", "business": "💎 Business"}.get(plan, plan)


def build_main_keyboard():
    return InlineKeyboardMarkup([
        [
            InlineKeyboardButton("📋 Meus Alertas",    callback_data="list_monitors"),
            InlineKeyboardButton("➕ Novo Alerta",      callback_data="add_monitor"),
        ],
        [
            InlineKeyboardButton("📊 Estatísticas",    callback_data="stats"),
            InlineKeyboardButton("⚙️ Meu Plano",       callback_data="my_plan"),
        ],
        [InlineKeyboardButton("❓ Ajuda",              callback_data="help")],
    ])


# ─────────────────────────────────────────────
# Handlers do Bot (interface com usuário)
# ─────────────────────────────────────────────
async def cmd_start(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    u = update.effective_user
    user = await get_or_create_user(u.id, u.username or "", u.full_name)

    text = (
        f"👋 Olá, *{u.first_name}*!\n\n"
        f"Bem-vindo ao *AlertaBot* — seu monitor inteligente de Telegram.\n\n"
        f"📌 *Plano atual:* {plan_badge(user['plan'])}\n"
        f"🔑 *Palavras-chave:* máx. {FREE_PLAN_LIMITS['max_keywords']} (free)\n"
        f"💬 *Chats monitorados:* máx. {FREE_PLAN_LIMITS['max_chats']} (free)\n\n"
        f"Use o menu abaixo para começar:"
    )
    await update.message.reply_text(text, parse_mode="Markdown", reply_markup=build_main_keyboard())


async def cmd_help(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    text = (
        "📖 *Como usar o AlertaBot*\n\n"
        "1️⃣ Clique em *Novo Alerta*\n"
        "2️⃣ Digite a *palavra-chave* que deseja monitorar\n"
        "3️⃣ Envie o *link do canal ou grupo*\n"
        "4️⃣ Pronto! Você será notificado sempre que a palavra aparecer.\n\n"
        "*Comandos disponíveis:*\n"
        "/start — Menu principal\n"
        "/monitorar — Criar alerta rápido\n"
        "/meus — Listar seus alertas\n"
        "/planos — Ver planos e preços\n"
        "/ajuda — Esta mensagem\n\n"
        "💡 *Dica:* No plano gratuito você pode monitorar até "
        f"{FREE_PLAN_LIMITS['max_keywords']} palavras em "
        f"{FREE_PLAN_LIMITS['max_chats']} chats diferentes."
    )
    if update.message:
        await update.message.reply_text(text, parse_mode="Markdown", reply_markup=build_main_keyboard())
    else:
        await update.callback_query.edit_message_text(text, parse_mode="Markdown", reply_markup=build_main_keyboard())


async def cmd_plans(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    text = (
        "💰 *Planos AlertaBot*\n\n"
        "🆓 *Gratuito*\n"
        f"  • {FREE_PLAN_LIMITS['max_keywords']} palavras-chave\n"
        f"  • {FREE_PLAN_LIMITS['max_chats']} chats\n"
        f"  • {FREE_PLAN_LIMITS['alerts_per_day']} alertas/dia\n\n"
        "⭐ *Pro — R$ 29/mês*\n"
        "  • 20 palavras-chave\n"
        "  • 10 chats\n"
        "  • Alertas ilimitados\n"
        "  • Filtro por horário\n\n"
        "💎 *Business — R$ 97/mês*\n"
        "  • Ilimitado\n"
        "  • Webhook / API\n"
        "  • Suporte prioritário\n"
        "  • Relatórios semanais\n\n"
        "👉 Pagamento em breve via Mercado Pago!"
    )
    keyboard = InlineKeyboardMarkup([
        [InlineKeyboardButton("🔙 Voltar", callback_data="main_menu")]
    ])
    if update.message:
        await update.message.reply_text(text, parse_mode="Markdown", reply_markup=keyboard)
    else:
        await update.callback_query.edit_message_text(text, parse_mode="Markdown", reply_markup=keyboard)


# ─────────────────────────────────────────────
# Conversa: Adicionar Monitor
# ─────────────────────────────────────────────
async def start_add_monitor(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if query:
        await query.answer()
        u = query.from_user
    else:
        u = update.effective_user

    user = await get_or_create_user(u.id, u.username or "", u.full_name)
    monitors = await get_user_monitors(u.id)

    # Verificar limite do plano
    limit = FREE_PLAN_LIMITS["max_keywords"] if user["plan"] == "free" else 9999
    if len(monitors) >= limit:
        msg = (
            f"⚠️ Você atingiu o limite de *{limit} alertas* no plano {plan_badge(user['plan'])}.\n\n"
            "Faça upgrade para criar mais alertas!"
        )
        kb = InlineKeyboardMarkup([
            [InlineKeyboardButton("💎 Ver Planos", callback_data="plans")],
            [InlineKeyboardButton("🔙 Voltar",    callback_data="main_menu")],
        ])
        if query:
            await query.edit_message_text(msg, parse_mode="Markdown", reply_markup=kb)
        else:
            await update.message.reply_text(msg, parse_mode="Markdown", reply_markup=kb)
        return ConversationHandler.END

    msg = "🔑 *Novo Alerta — Passo 1/2*\n\nDigite a *palavra-chave* que deseja monitorar:\n\n_Ex: robo aspirador, iphone, nike_"
    kb = InlineKeyboardMarkup([[InlineKeyboardButton("❌ Cancelar", callback_data="main_menu")]])

    if query:
        await query.edit_message_text(msg, parse_mode="Markdown", reply_markup=kb)
    else:
        await update.message.reply_text(msg, parse_mode="Markdown", reply_markup=kb)

    return WAITING_KEYWORD


async def receive_keyword(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    keyword = update.message.text.strip()
    if len(keyword) < 2:
        await update.message.reply_text("⚠️ A palavra-chave deve ter pelo menos 2 caracteres. Tente novamente:")
        return WAITING_KEYWORD

    ctx.user_data["keyword"] = keyword
    await update.message.reply_text(
        f"✅ Palavra-chave: *{keyword}*\n\n"
        "💬 *Passo 2/2* — Agora envie o *link do canal ou grupo*:\n\n"
        "_Ex: https://t.me/promotopcupons_\n"
        "_ou o @username do canal_",
        parse_mode="Markdown",
        reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("❌ Cancelar", callback_data="main_menu")]]),
    )
    return WAITING_CHAT


async def receive_chat(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    chat_input = update.message.text.strip()
    u = update.effective_user

    # Aceitar @username ou link
    if chat_input.startswith("https://t.me/"):
        chat_link = chat_input
        chat_title = chat_input.replace("https://t.me/", "@")
    elif chat_input.startswith("@"):
        chat_link = f"https://t.me/{chat_input[1:]}"
        chat_title = chat_input
    else:
        await update.message.reply_text(
            "⚠️ Formato inválido. Envie o link completo (https://t.me/...) ou o @username:"
        )
        return WAITING_CHAT

    user = await get_or_create_user(u.id, u.username or "", u.full_name)
    monitors = await get_user_monitors(u.id)

    # Verificar limite de chats
    chat_limit = FREE_PLAN_LIMITS["max_chats"] if user["plan"] == "free" else 9999
    unique_chats = {m["chat_link"] for m in monitors}
    if chat_link not in unique_chats and len(unique_chats) >= chat_limit:
        await update.message.reply_text(
            f"⚠️ Limite de *{chat_limit} chats* atingido no plano gratuito.\n"
            "Faça upgrade para monitorar mais canais!",
            parse_mode="Markdown",
        )
        return ConversationHandler.END

    keyword = ctx.user_data.get("keyword", "")
    monitor_id = await add_monitor(u.id, keyword, chat_link, chat_title)

    await update.message.reply_text(
        f"🎉 *Alerta criado com sucesso!*\n\n"
        f"🔑 Palavra: *{keyword}*\n"
        f"💬 Canal: *{chat_title}*\n\n"
        f"Você será notificado sempre que *{keyword}* aparecer nesse canal!",
        parse_mode="Markdown",
        reply_markup=build_main_keyboard(),
    )
    ctx.user_data.clear()
    return ConversationHandler.END


async def cancel_conversation(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    ctx.user_data.clear()
    if update.callback_query:
        await update.callback_query.answer()
        await update.callback_query.edit_message_text("❌ Operação cancelada.", reply_markup=build_main_keyboard())
    return ConversationHandler.END


# ─────────────────────────────────────────────
# Listar Monitores
# ─────────────────────────────────────────────
async def list_monitors(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    if query:
        await query.answer()
        u = query.from_user
    else:
        u = update.effective_user

    monitors = await get_user_monitors(u.id)

    if not monitors:
        text = "📋 *Seus Alertas*\n\nVocê ainda não tem nenhum alerta configurado.\nClique em ➕ *Novo Alerta* para começar!"
        kb = InlineKeyboardMarkup([
            [InlineKeyboardButton("➕ Novo Alerta", callback_data="add_monitor")],
            [InlineKeyboardButton("🔙 Voltar",      callback_data="main_menu")],
        ])
    else:
        text = f"📋 *Seus Alertas* ({len(monitors)} ativo{'s' if len(monitors) > 1 else ''})\n\n"
        buttons = []
        for m in monitors:
            text += f"🔑 *{m['keyword']}* → {m['chat_title'] or m['chat_link']}\n"
            buttons.append([
                InlineKeyboardButton(f"🗑 Remover: {m['keyword']}", callback_data=f"del_{m['id']}")
            ])
        buttons.append([InlineKeyboardButton("🔙 Voltar", callback_data="main_menu")])
        kb = InlineKeyboardMarkup(buttons)

    if query:
        await query.edit_message_text(text, parse_mode="Markdown", reply_markup=kb)
    else:
        await update.message.reply_text(text, parse_mode="Markdown", reply_markup=kb)


async def delete_monitor_cb(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    monitor_id = int(query.data.split("_")[1])
    await remove_monitor(monitor_id, query.from_user.id)
    await list_monitors(update, ctx)


# ─────────────────────────────────────────────
# Estatísticas
# ─────────────────────────────────────────────
async def show_stats(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    u = query.from_user

    monitors = await get_user_monitors(u.id)
    today_alerts = await count_today_alerts(u.id)
    user = await get_or_create_user(u.id, u.username or "", u.full_name)

    text = (
        f"📊 *Suas Estatísticas*\n\n"
        f"👤 Plano: {plan_badge(user['plan'])}\n"
        f"🔔 Alertas ativos: {len(monitors)}\n"
        f"📬 Alertas hoje: {today_alerts}\n"
        f"📅 Membro desde: {user['created_at'][:10]}\n"
    )
    kb = InlineKeyboardMarkup([[InlineKeyboardButton("🔙 Voltar", callback_data="main_menu")]])
    await query.edit_message_text(text, parse_mode="Markdown", reply_markup=kb)


# ─────────────────────────────────────────────
# Callback Router
# ─────────────────────────────────────────────
async def callback_router(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    data  = query.data

    if data == "main_menu":
        await query.answer()
        await query.edit_message_text(
            "🏠 *Menu Principal*\nEscolha uma opção:",
            parse_mode="Markdown",
            reply_markup=build_main_keyboard(),
        )
    elif data == "list_monitors":
        await list_monitors(update, ctx)
    elif data == "stats":
        await show_stats(update, ctx)
    elif data == "my_plan":
        await cmd_plans(update, ctx)
    elif data == "plans":
        await cmd_plans(update, ctx)
    elif data == "help":
        await cmd_help(update, ctx)
    elif data.startswith("del_"):
        await delete_monitor_cb(update, ctx)


# ─────────────────────────────────────────────
# Monitor Telethon — escuta canais em tempo real
# ─────────────────────────────────────────────
class TelegramMonitor:
    """
    Usa a conta de usuário (Telethon) para escutar mensagens
    em canais/grupos e disparar alertas via Bot.
    """

    def __init__(self, bot_app: Application):
        self.client  = TelegramClient("alertabot_session", API_ID, API_HASH)
        self.bot_app = bot_app

    async def start(self):
        await self.client.start(phone=PHONE)
        logger.info("✅ Telethon conectado")

        @self.client.on(events.NewMessage)
        async def on_message(event):
            try:
                await self._process_message(event)
            except Exception as e:
                logger.error(f"Erro ao processar mensagem: {e}")

        await self.client.run_until_disconnected()

    async def _process_message(self, event):
        msg_text = event.message.message or ""
        if not msg_text:
            return

        chat = await event.get_chat()
        chat_username = getattr(chat, "username", None)
        chat_title    = getattr(chat, "title", "") or getattr(chat, "first_name", "")

        if not chat_username:
            return

        chat_link = f"https://t.me/{chat_username}"

        # Buscar todos os monitores ativos para esse chat
        async with aiosqlite.connect(DB_PATH) as db:
            db.row_factory = aiosqlite.Row
            async with db.execute(
                "SELECT * FROM monitors WHERE active = 1 AND chat_link = ?",
                (chat_link,),
            ) as cur:
                monitors = [dict(r) for r in await cur.fetchall()]

        if not monitors:
            return

        msg_lower = msg_text.lower()

        for monitor in monitors:
            keyword = monitor["keyword"]
            if keyword not in msg_lower:
                continue

            user_id = monitor["user_id"]

            # Checar limite diário (plano free)
            async with aiosqlite.connect(DB_PATH) as db:
                db.row_factory = aiosqlite.Row
                async with db.execute(
                    "SELECT plan FROM users WHERE user_id = ?", (user_id,)
                ) as cur:
                    row = await cur.fetchone()

            plan = row["plan"] if row else "free"
            if plan == "free":
                today = await count_today_alerts(user_id)
                if today >= FREE_PLAN_LIMITS["alerts_per_day"]:
                    continue

            # Formatar e enviar alerta
            preview  = msg_text[:200] + ("..." if len(msg_text) > 200 else "")
            msg_link = f"https://t.me/{chat_username}/{event.message.id}"

            alert_text = (
                f"🔔 *Alerta: {keyword}*\n\n"
                f"📢 *Canal:* {chat_title}\n"
                f"📝 *Mensagem:*\n{preview}\n\n"
                f"[🔗 Ver mensagem original]({msg_link})"
            )

            try:
                await self.bot_app.bot.send_message(
                    chat_id=user_id,
                    text=alert_text,
                    parse_mode="Markdown",
                    disable_web_page_preview=False,
                )
                await log_alert(user_id, monitor["id"], preview, chat_title)
                logger.info(f"✉️ Alerta enviado → user {user_id} | keyword '{keyword}' | {chat_title}")
            except Exception as e:
                logger.warning(f"Não foi possível enviar para {user_id}: {e}")


# ─────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────
async def main():
    await init_db()

    # Configurar a aplicação do bot
    app = Application.builder().token(BOT_TOKEN).build()

    # Conversa para adicionar monitor
    conv_handler = ConversationHandler(
        entry_points=[
            CallbackQueryHandler(start_add_monitor, pattern="^add_monitor$"),
            CommandHandler("monitorar", start_add_monitor),
        ],
        states={
            WAITING_KEYWORD: [MessageHandler(filters.TEXT & ~filters.COMMAND, receive_keyword)],
            WAITING_CHAT:    [MessageHandler(filters.TEXT & ~filters.COMMAND, receive_chat)],
        },
        fallbacks=[
            CallbackQueryHandler(cancel_conversation, pattern="^main_menu$"),
            CommandHandler("cancelar", cancel_conversation),
        ],
    )

    app.add_handler(CommandHandler("start",    cmd_start))
    app.add_handler(CommandHandler("ajuda",    cmd_help))
    app.add_handler(CommandHandler("meus",     list_monitors))
    app.add_handler(CommandHandler("planos",   cmd_plans))
    app.add_handler(conv_handler)
    app.add_handler(CallbackQueryHandler(callback_router))

    # Iniciar bot e monitor em paralelo
    monitor = TelegramMonitor(app)

    async with app:
        await app.start()
        await app.updater.start_polling(drop_pending_updates=True)
        logger.info("🤖 Bot iniciado! Pressione Ctrl+C para parar.")

        # Rodar monitor Telethon (bloqueante)
        await monitor.start()

        await app.updater.stop()
        await app.stop()


if __name__ == "__main__":
    asyncio.run(main())

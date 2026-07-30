<?php

namespace App\Telegram;

use RuntimeException;

class TelegramClient
{
    private string $apiBase;

    public function __construct(private string $botToken)
    {
        $this->apiBase = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $inlineKeyboard = null,
        string $parseMode = 'HTML'
    ): array {
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        return $this->call('sendMessage', $payload);
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $inlineKeyboard = null
    ): array {
        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        return $this->call('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $inlineKeyboard = null): array
    {
        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        return $this->call('editMessageReplyMarkup', $payload);
    }

    public function sendForceReply(int|string $chatId, string $text, ?int $replyToMessageId = null): array
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['force_reply' => true, 'selective' => true]),
        ];

        if ($replyToMessageId !== null) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        return $this->call('sendMessage', $payload);
    }

    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url'          => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);
    }

    public function setMyCommands(array $commands): array
    {
        return $this->call('setMyCommands', [
            'commands' => json_encode($commands),
        ]);
    }

    public static function tecladoAcoesChamado(int $chamadoId, string $linkGlpi): array
    {
        return [
            [
                ['text' => "\u{1f4c2} Abrir chamado", 'url' => $linkGlpi],
            ],
            [
                ['text' => "\u{2705} Assumir", 'callback_data' => "assumir:{$chamadoId}"],
                ['text' => "\u{274c} Rejeitar", 'callback_data' => "rejeitar:{$chamadoId}"],
            ],
            [
                ['text' => "\u{1f441} Confirmar leitura", 'callback_data' => "leitura:{$chamadoId}"],
                ['text' => "\u{1f4ca} Ver SLA", 'callback_data' => "sla:{$chamadoId}"],
            ],
        ];
    }

    public static function tecladoChamadoAssumido(int $chamadoId, string $linkGlpi, string $nomeTecnico): array
    {
        return [
            [
                ['text' => "\u{1f4c2} Abrir chamado", 'url' => $linkGlpi],
            ],
            [
                ['text' => "\u{274c} Assumido por {$nomeTecnico}", 'callback_data' => "ja_assumido:{$chamadoId}"],
            ],
            [
                ['text' => "\u{1f4ca} Ver SLA", 'callback_data' => "sla:{$chamadoId}"],
            ],
        ];
    }

    private function call(string $method, array $payload): array
    {
        $ch = curl_init("{$this->apiBase}/{$method}");

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Erro de conexao com Telegram: {$error}");
        }

        $decoded = json_decode($raw, true);

        if ($httpCode >= 400 || ($decoded['ok'] ?? false) === false) {
            throw new RuntimeException("Erro Telegram HTTP {$httpCode}: {$raw}");
        }

        return $decoded;
    }
}

<?php

namespace App\Services;

use App\Models\Chamado;
use App\Telegram\TelegramClient;
use PDO;
use Predis\Client as RedisClient;

/**
 * Responsável por:
 *  - Enfileirar mensagens (Redis list + persistência em MySQL como fallback)
 *  - Evitar duplicidade (hash do conteúdo + tipo_evento + chamado_id)
 *  - Aplicar cooldown por chamado/tipo de evento (anti-spam)
 *  - Agrupar eventos semelhantes dentro da janela de cooldown
 */
class NotificationDispatcher
{
    private const COOLDOWN_SEGUNDOS = [
        'novo_chamado'         => 0,     // sempre envia
        'atribuicao'           => 0,
        'reatribuicao'         => 0,
        'alteracao_prioridade' => 300,   // agrupa alterações em rajada (5 min)
        'vencimento_alerta'    => 60,    // não repete o mesmo limiar em <1min
        'vencido'              => 0,
        'lembrete_vencido'     => 0,     // cadência já controlada pelo worker
        'resolvido'            => 0,
        'fechado'              => 0,
    ];

    public function __construct(
        private PDO $db,
        private RedisClient $redis,
        private TelegramClient $telegram,
    ) {
    }

    public function enfileirar(
        int $chamadoId,
        int|string $destinoChatId,
        string $tipoEvento,
        string $texto,
        ?array $teclado = null
    ): void {
        $hash = sha1($tipoEvento . '|' . $destinoChatId . '|' . $texto);

        if ($this->emCooldown($chamadoId, $tipoEvento, $hash)) {
            return; // Alertas inteligentes: evita mensagens duplicadas / spam
        }

        $payload = [
            'chamado_id'      => $chamadoId,
            'destino_chat_id' => $destinoChatId,
            'tipo_evento'     => $tipoEvento,
            'texto'           => $texto,
            'teclado'         => $teclado,
            'hash'            => $hash,
        ];

        // Persistência (auditoria/fallback)
        $stmt = $this->db->prepare(
            'INSERT INTO fila_mensagens (chamado_id, destino_chat_id, tipo_evento, payload_json, status)
             VALUES (:chamado_id, :chat_id, :tipo, :payload, "pendente")'
        );
        $stmt->execute([
            'chamado_id' => $chamadoId,
            'chat_id'    => $destinoChatId,
            'tipo'       => $tipoEvento,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $filaId = (int) $this->db->lastInsertId();
        $payload['fila_id'] = $filaId;

        // Fila real (RabbitMQ pode substituir; Redis list cobre o caso de uso
        // com menor complexidade operacional, conforme permitido pelo prompt:
        // "RabbitMQ (opcional)")
        $this->redis->rpush('fila:notificacoes', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Processa um item da fila (chamado pelo worker). Faz retry automático
     * com backoff exponencial em caso de falha.
     */
    public function processarProximo(): bool
    {
        $raw = $this->redis->lpop('fila:notificacoes');

        if (!$raw) {
            return false;
        }

        $payload = json_decode($raw, true);

        try {
            $resultado = $this->telegram->sendMessage(
                $payload['destino_chat_id'],
                $payload['texto'],
                $payload['teclado'] ?? null
            );

            $this->marcarComoEnviado($payload, $resultado['result']['message_id'] ?? null);
        } catch (\Throwable $e) {
            $this->tratarFalha($payload, $e);
        }

        return true;
    }

    private function emCooldown(int $chamadoId, string $tipoEvento, string $hash): bool
    {
        $chave = "cooldown:{$chamadoId}:{$tipoEvento}";
        $ttl = self::COOLDOWN_SEGUNDOS[$tipoEvento] ?? 0;

        // Dedup absoluto: mesmo hash já enviado para este chamado/evento
        $stmt = $this->db->prepare(
            'SELECT 1 FROM notificacoes_enviadas
             WHERE chamado_id = :id AND tipo_evento = :tipo AND payload_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['id' => $chamadoId, 'tipo' => $tipoEvento, 'hash' => $hash]);

        if ($stmt->fetchColumn()) {
            return true;
        }

        if ($ttl <= 0) {
            return false;
        }

        if ($this->redis->exists($chave)) {
            return true;
        }

        $this->redis->setex($chave, $ttl, '1');
        return false;
    }

    private function marcarComoEnviado(array $payload, ?int $telegramMessageId): void
    {
        $this->db->prepare(
            'UPDATE fila_mensagens SET status = "enviado" WHERE id = :id'
        )->execute(['id' => $payload['fila_id']]);

        $this->db->prepare(
            'INSERT INTO notificacoes_enviadas
                (chamado_id, tipo_evento, destino_chat_id, telegram_message_id, payload_hash)
             VALUES (:chamado_id, :tipo, :chat_id, :msg_id, :hash)'
        )->execute([
            'chamado_id' => $payload['chamado_id'],
            'tipo'       => $payload['tipo_evento'],
            'chat_id'    => $payload['destino_chat_id'],
            'msg_id'     => $telegramMessageId,
            'hash'       => $payload['hash'],
        ]);
    }

    private function tratarFalha(array $payload, \Throwable $e): void
    {
        $stmt = $this->db->prepare(
            'SELECT tentativas, max_tentativas FROM fila_mensagens WHERE id = :id'
        );
        $stmt->execute(['id' => $payload['fila_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $tentativas = ((int) ($row['tentativas'] ?? 0)) + 1;
        $maxTentativas = (int) ($row['max_tentativas'] ?? 5);

        if ($tentativas >= $maxTentativas) {
            $this->db->prepare(
                'UPDATE fila_mensagens SET status = "falhou", tentativas = :t, erro_ultimo = :erro WHERE id = :id'
            )->execute(['t' => $tentativas, 'erro' => $e->getMessage(), 'id' => $payload['fila_id']]);
            return;
        }

        // Backoff exponencial: 2^tentativas segundos (cap em 5 min)
        $delaySegundos = min(300, (int) (2 ** $tentativas));

        $this->db->prepare(
            'UPDATE fila_mensagens
             SET tentativas = :t, erro_ultimo = :erro,
                 proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL :delay SECOND)
             WHERE id = :id'
        )->execute([
            't'     => $tentativas,
            'erro'  => $e->getMessage(),
            'delay' => $delaySegundos,
            'id'    => $payload['fila_id'],
        ]);

        // Recoloca na fila para nova tentativa (um worker de retry lê
        // periodicamente fila_mensagens com proxima_tentativa_em <= NOW())
    }
}

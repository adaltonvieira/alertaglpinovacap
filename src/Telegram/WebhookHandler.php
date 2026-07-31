<?php

namespace App\Telegram;

use App\GLPI\GlpiClient;
use App\Models\Chamado;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use App\Services\SlaEngine;
use App\Support\GlpiUrl;
use PDO;

/**
 * Processa os updates recebidos via webhook do Telegram: callback_query
 * dos botoes (Assumir, Rejeitar, Confirmar leitura, Ver SLA) e as respostas
 * de texto livre do fluxo de rejeicao (ForceReply).
 *
 * "Abrir chamado" NAO passa por aqui - e um botao de URL, o proprio
 * Telegram abre o link direto, sem falar com nosso backend.
 */
class WebhookHandler
{
    public function __construct(
        private PDO $db,
        private GlpiClient $glpi,
        private TelegramClient $telegram,
        private SlaEngine $sla,
        private MessageFormatter $formatter,
        private NotificationDispatcher $dispatcher,
        private BotCommands $botCommands,
    ) {
    }

    public function processar(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->processarCallback($update['callback_query']);
            return;
        }

        if (isset($update['message'])) {
            if ($this->tentarProcessarRespostaDeRejeicao($update['message'])) {
                return;
            }

            if (isset($update['message']['text'])) {
                $this->botCommands->processar($update['message']);
            }
        }
    }

    private function processarCallback(array $callback): void
    {
        $callbackId = $callback['id'];
        $dataRaw = $callback['data'] ?? '';
        $telegramUserId = (int) ($callback['from']['id'] ?? 0);

        [$acao, $chamadoIdStr] = array_pad(explode(':', $dataRaw, 2), 2, null);
        $chamadoId = (int) $chamadoIdStr;

        if ($chamadoId <= 0) {
            $this->responderCallback($callbackId, 'Chamado invalido.', true);
            return;
        }

        $tecnico = $this->buscarTecnicoPorTelegramId($telegramUserId);

        if ($tecnico === null) {
            $this->responderCallback(
                $callbackId,
                'Voce nao esta cadastrado como tecnico neste sistema.',
                true
            );
            return;
        }

        $chamado = $this->buscarChamado($chamadoId);

        if ($chamado === null) {
            $this->responderCallback($callbackId, 'Chamado nao encontrado.', true);
            return;
        }

        match ($acao) {
            'assumir'     => $this->assumirChamado($callbackId, $chamado, $tecnico),
            'rejeitar'    => $this->iniciarRejeicao($callbackId, $chamado, $tecnico),
            'leitura'     => $this->confirmarLeitura($callbackId, $chamado, $tecnico),
            'sla'         => $this->mostrarSla($callbackId, $chamado),
            'ja_assumido' => $this->responderCallback($callbackId, 'Este chamado ja foi assumido.', true),
            default       => $this->responderCallback($callbackId, 'Acao desconhecida.', true),
        };
    }

    private function assumirChamado(string $callbackId, Chamado $chamado, array $tecnico): void
    {
        if ($chamado->tecnicoAtualId !== null && $chamado->tecnicoAtualId !== (int) $tecnico['id']) {
            $nomeAtual = $this->buscarNomeTecnico($chamado->tecnicoAtualId);
            $this->responderCallback(
                $callbackId,
                "Este chamado ja esta atribuido a {$nomeAtual}.",
                true
            );
            return;
        }

        if ($chamado->tecnicoAtualId === (int) $tecnico['id']) {
            $this->responderCallback($callbackId, 'Voce ja esta atribuido a este chamado.', false);
            return;
        }

        try {
            $this->glpi->atribuirTecnico($chamado->glpiTicketId, (int) $tecnico['glpi_user_id']);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao atribuir tecnico no GLPI: ' . $e->getMessage());
            $this->responderCallback(
                $callbackId,
                'Erro ao atribuir no GLPI. Tente novamente ou assuma direto pelo sistema.',
                true
            );
            return;
        }

        try {
            $this->db->prepare(
                'UPDATE chamados SET tecnico_atual_id = :tecnico, status_glpi = :status WHERE id = :id'
            )->execute([
                'tecnico' => $tecnico['id'],
                'status'  => 'atribuido',
                'id'      => $chamado->id,
            ]);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] GLPI atribuido, mas falhou ao atualizar banco local: ' . $e->getMessage());
        }

        // Atualiza os botoes em TODAS as mensagens que mencionam este
        // chamado (grupo + qualquer mensagem individual), para que quem
        // mais olhar veja que ja foi assumido - evita duas pessoas
        // tentando assumir o mesmo chamado ao mesmo tempo.
        $this->atualizarBotoesComoAssumido($chamado, $tecnico['nome']);

        // Notifica o proprio tecnico imediatamente (nao espera o proximo
        // ciclo de sincronizacao, que so rodaria daqui uns 20s).
        try {
            $this->dispatcher->enfileirar(
                $chamado->id,
                (int) $tecnico['telegram_chat_id'],
                'atribuicao',
                $this->formatter->chamadoAtribuido($chamado, $tecnico['nome']),
                TelegramClient::tecladoChamadoAtribuido($chamado->id, $chamado->linkGlpi)
            );
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao enviar notificacao de atribuicao (nao critico): ' . $e->getMessage());
        }

        $this->responderCallback($callbackId, 'Chamado assumido com sucesso!', false);
    }

    private function atualizarBotoesComoAssumido(Chamado $chamado, string $nomeTecnico): void
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT destino_chat_id, telegram_message_id
                 FROM notificacoes_enviadas
                 WHERE chamado_id = :id
                   AND tipo_evento IN ('novo_chamado', 'atribuicao', 'reatribuicao')
                   AND telegram_message_id IS NOT NULL"
            );
            $stmt->execute(['id' => $chamado->id]);
            $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao buscar mensagens do chamado: ' . $e->getMessage());
            return;
        }

        $novoTeclado = TelegramClient::tecladoChamadoAssumido($chamado->id, $chamado->linkGlpi, $nomeTecnico);

        foreach ($mensagens as $msg) {
            try {
                $this->telegram->editMessageReplyMarkup(
                    (int) $msg['destino_chat_id'],
                    (int) $msg['telegram_message_id'],
                    $novoTeclado
                );
            } catch (\Throwable $e) {
                // Nao critico - a mensagem pode ter sido apagada, ou o bot
                // pode nao ter mais permissao naquele chat. So loga.
                error_log('[WebhookHandler] Falha ao atualizar botoes de uma mensagem (nao critico): ' . $e->getMessage());
            }
        }
    }

    private function iniciarRejeicao(string $callbackId, Chamado $chamado, array $tecnico): void
    {
        if ($chamado->tecnicoAtualId !== (int) $tecnico['id']) {
            $this->responderCallback(
                $callbackId,
                'Voce so pode rejeitar chamados que estao atribuidos a voce.',
                true
            );
            return;
        }

        if ($tecnico['telegram_chat_id'] === null) {
            $this->responderCallback($callbackId, 'Erro interno: chat privado nao encontrado.', true);
            return;
        }

        $this->responderCallback(
            $callbackId,
            'Verifique sua mensagem privada com o bot para informar o motivo.',
            false
        );

        try {
            $resultado = $this->telegram->sendForceReply(
                (int) $tecnico['telegram_chat_id'],
                "Por favor, informe o motivo da rejeicao do chamado #{$chamado->numero}:"
            );

            $messageId = $resultado['result']['message_id'] ?? null;

            if ($messageId !== null) {
                $this->db->prepare(
                    'INSERT INTO rejeicoes_pendentes (chamado_id, telegram_user_id, prompt_chat_id, prompt_message_id)
                     VALUES (:chamado_id, :user_id, :chat_id, :message_id)'
                )->execute([
                    'chamado_id' => $chamado->id,
                    'user_id'    => $tecnico['telegram_chat_id'],
                    'chat_id'    => $tecnico['telegram_chat_id'],
                    'message_id' => $messageId,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao iniciar fluxo de rejeicao: ' . $e->getMessage());
        }
    }

    private function tentarProcessarRespostaDeRejeicao(array $message): bool
    {
        $chatId = $message['chat']['id'] ?? null;
        $texto = trim($message['text'] ?? '');

        // Nao exige reply formal (o ForceReply do Telegram deveria ativar
        // isso sozinho, mas nem sempre gruda em todos os clientes/versoes).
        // Qualquer texto simples (que nao seja um comando com "/") enquanto
        // houver uma rejeicao pendente nesse chat privado ja conta como
        // motivo - reduz friccao pro tecnico.
        if ($chatId === null || $texto === '' || $texto[0] === '/') {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM rejeicoes_pendentes WHERE prompt_chat_id = :chat_id ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['chat_id' => $chatId]);
            $pendente = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao consultar rejeicoes pendentes: ' . $e->getMessage());
            return false;
        }

        if (!$pendente) {
            return false;
        }

        $this->processarRejeicao((int) $pendente['id'], (int) $pendente['chamado_id'], $chatId, $texto);
        return true;
    }

    private function processarRejeicao(int $pendenteId, int $chamadoId, int|string $chatId, string $motivo): void
    {
        $chamado = $this->buscarChamado($chamadoId);

        $this->removerRejeicaoPendente($pendenteId);

        if ($chamado === null) {
            return;
        }

        try {
            $this->glpi->adicionarAcompanhamento(
                $chamado->glpiTicketId,
                "Chamado rejeitado via Telegram. Motivo: {$motivo}",
                true
            );
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao registrar rejeicao no GLPI: ' . $e->getMessage());
        }

        try {
            $this->db->prepare(
                'UPDATE chamados SET tecnico_atual_id = NULL, status_glpi = :status WHERE id = :id'
            )->execute([
                'status' => 'novo',
                'id'     => $chamado->id,
            ]);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao limpar tecnico apos rejeicao: ' . $e->getMessage());
        }

        $this->telegram->sendMessage(
            $chatId,
            "Rejeicao registrada. O chamado #{$chamado->numero} foi devolvido para a fila da equipe."
        );

        $grupo = $this->chatGrupoPorEquipe($chamado->equipeAtual);
        if ($grupo !== null) {
            try {
                $this->dispatcher->enfileirar(
                    $chamado->id,
                    $grupo,
                    'novo_chamado',
                    $this->formatter->chamadoRejeitado($chamado, $motivo),
                    TelegramClient::tecladoAcoesChamado($chamado->id, $chamado->linkGlpi)
                );
            } catch (\Throwable $e) {
                error_log('[WebhookHandler] Falha ao notificar grupo sobre rejeicao: ' . $e->getMessage());
            }
        }
    }

    private function removerRejeicaoPendente(int $pendenteId): void
    {
        try {
            $this->db->prepare('DELETE FROM rejeicoes_pendentes WHERE id = :id')->execute(['id' => $pendenteId]);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao remover rejeicao pendente: ' . $e->getMessage());
        }
    }

    private function confirmarLeitura(string $callbackId, Chamado $chamado, array $tecnico): void
    {
        try {
            $this->db->prepare(
                'INSERT INTO confirmacoes_leitura (chamado_id, tecnico_id)
                 VALUES (:chamado_id, :tecnico_id)
                 ON DUPLICATE KEY UPDATE confirmado_em = confirmado_em'
            )->execute([
                'chamado_id' => $chamado->id,
                'tecnico_id' => $tecnico['id'],
            ]);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao gravar confirmacao de leitura: ' . $e->getMessage());
        }

        $this->responderCallback($callbackId, 'Leitura confirmada.', false);
    }

    private function mostrarSla(string $callbackId, Chamado $chamado): void
    {
        $percentual = $chamado->percentualSlaConsumido();
        $vencido = $chamado->estaVencido();

        $texto = $vencido
            ? "SLA VENCIDO\nPrazo era: {$chamado->prazoResolucao->format('d/m/Y H:i')}"
            : "SLA em andamento: {$percentual}% consumido\nPrazo: {$chamado->prazoResolucao->format('d/m/Y H:i')}";

        $this->responderCallback($callbackId, $texto, true);
    }

    private function responderCallback(string $callbackId, string $texto, bool $showAlert): void
    {
        try {
            $this->telegram->answerCallbackQuery($callbackId, $texto, $showAlert);
        } catch (\Throwable $e) {
            error_log('[WebhookHandler] Falha ao responder callback (nao critico): ' . $e->getMessage());
        }
    }

    private function buscarTecnicoPorTelegramId(int $telegramUserId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tecnicos WHERE telegram_chat_id = :id AND ativo = 1');
        $stmt->execute(['id' => $telegramUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function buscarNomeTecnico(int $tecnicoId): string
    {
        $stmt = $this->db->prepare('SELECT nome FROM tecnicos WHERE id = :id');
        $stmt->execute(['id' => $tecnicoId]);
        return $stmt->fetchColumn() ?: '(desconhecido)';
    }

    private function chatGrupoPorEquipe(string $equipe): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT chat_id FROM grupos_telegram
             WHERE (equipe_vinculada = :equipe OR tipo = 'novo_chamado') AND ativo = 1
             ORDER BY (equipe_vinculada = :equipe2) DESC
             LIMIT 1"
        );
        $stmt->execute(['equipe' => $equipe, 'equipe2' => $equipe]);
        $chatId = $stmt->fetchColumn();

        if ($chatId !== false) {
            return (int) $chatId;
        }

        $fallback = getenv('TELEGRAM_GRUPO_NOVO_CHAMADO_ID');
        return $fallback ? (int) $fallback : null;
    }

    private function buscarChamado(int $chamadoId): ?Chamado
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, l.nome AS localidade_nome
             FROM chamados c
             LEFT JOIN localidades l ON l.id = c.localidade_id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => $chamadoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['link_glpi'] = GlpiUrl::ticketLink((int) $row['glpi_ticket_id']);
        return Chamado::fromArray($row);
    }
}

<?php

namespace App\Telegram;

use App\GLPI\GlpiClient;
use App\Models\Chamado;
use App\Services\SlaEngine;
use App\Support\GlpiUrl;
use PDO;

class WebhookHandler
{
    public function __construct(
        private PDO $db,
        private GlpiClient $glpi,
        private TelegramClient $telegram,
        private SlaEngine $sla,
        private BotCommands $botCommands,
    ) {
    }

    public function processar(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->processarCallback($update['callback_query']);
            return;
        }

        if (isset($update['message']['text'])) {
            $this->botCommands->processar($update['message']);
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
            'assumir' => $this->assumirChamado($callbackId, $chamado, $tecnico),
            'leitura' => $this->confirmarLeitura($callbackId, $chamado, $tecnico),
            'sla'     => $this->mostrarSla($callbackId, $chamado),
            default   => $this->responderCallback($callbackId, 'Acao desconhecida.', true),
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

        $this->responderCallback($callbackId, 'Chamado assumido com sucesso!', false);
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

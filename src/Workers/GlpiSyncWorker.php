<?php

namespace App\Workers;

use App\GLPI\GlpiClient;
use App\Services\SlaEngine;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use PDO;
use DateTimeImmutable;

/**
 * Worker executado via cron (a cada N segundos — ver docker/crontab) que:
 *  1. Busca chamados novos/alterados no GLPI desde a última execução.
 *  2. Detecta os eventos: novo chamado, atribuição, reatribuição,
 *     alteração de prioridade/SLA, resolução, fechamento.
 *  3. Persiste o estado local (tabela `chamados`) e dispara notificações.
 *
 * Preferência do prompt: usar Webhooks quando disponíveis. Este worker cobre
 * o caso "monitoramento inteligente via API utilizando polling otimizado"
 * para quando Webhooks não estiverem habilitados no GLPI, e também serve
 * de reconciliador (safety net) mesmo quando webhooks estão ativos.
 */
class GlpiSyncWorker
{
    public function __construct(
        private GlpiClient $glpi,
        private PDO $db,
        private SlaEngine $sla,
        private MessageFormatter $formatter,
        private NotificationDispatcher $dispatcher,
        private array $config,
    ) {
    }

    public function executar(): void
    {
        $desde = $this->obterUltimoCheckpoint();
        $resultado = $this->glpi->buscarTicketsAtualizadosDesde($desde);

        foreach ($resultado['data'] ?? $resultado as $linha) {
            $ticketId = (int) ($linha['2'] ?? $linha['id'] ?? 0); // campo GLPI '2' = ID em /search
            if ($ticketId <= 0) {
                continue;
            }

            $this->processarTicket($ticketId);
        }

        $this->atualizarCheckpoint(new DateTimeImmutable());
    }

    private function processarTicket(int $ticketId): void
    {
        $ticket = $this->glpi->getTicket($ticketId);

        $existente = $this->buscarChamadoLocal($ticketId);

        $impacto    = $this->mapearImpacto($ticket['impact'] ?? 3);
        $urgencia   = $this->mapearUrgencia($ticket['urgency'] ?? 3);
        $tipo       = ((int) ($ticket['type'] ?? 1)) === 2 ? 'REQUISICAO' : 'INCIDENTE';
        $criticidade = $this->sla->calcularCriticidade($impacto, $urgencia);

        $abertoEm = new DateTimeImmutable($ticket['date'] ?? 'now');
        $equipe   = $this->equipeResponsavelPorGrupo((int) ($ticket['groups_id_assign'] ?? 0));

        $prazoInicio = $this->sla->calcularPrazoLimite(
            $abertoEm,
            $this->sla->prazoInicioAtendimentoMinutos($criticidade),
            $equipe
        );
        $prazoResolucao = $this->sla->calcularPrazoLimite(
            $abertoEm,
            $this->sla->prazoResolucaoMinutos($tipo, $criticidade),
            $equipe
        );

        $statusGlpi = $this->mapearStatus((int) ($ticket['status'] ?? 1));
        $tecnicoAtualId = $this->resolverTecnicoAtribuido($ticketId);

        if ($existente === null) {
            $this->inserirChamado($ticket, $ticketId, $tipo, $impacto, $urgencia, $criticidade,
                $equipe, $statusGlpi, $abertoEm, $prazoInicio, $prazoResolucao, $tecnicoAtualId);

            $this->notificarNovoChamado($ticketId, $tecnicoAtualId);
            return;
        }

        $this->detectarEventosEAtualizar($existente, [
            'impacto'          => $impacto,
            'urgencia'         => $urgencia,
            'criticidade'      => $criticidade,
            'status_glpi'      => $statusGlpi,
            'tecnico_atual_id' => $tecnicoAtualId,
            'prazo_resolucao'  => $prazoResolucao,
        ]);
    }

    private function detectarEventosEAtualizar(array $antes, array $depois): void
    {
        $chamadoId = (int) $antes['id'];

        $mudouTecnico = $antes['tecnico_atual_id'] != $depois['tecnico_atual_id'];
        $mudouCriticidade = $antes['criticidade'] !== $depois['criticidade']
            || $antes['impacto'] !== $depois['impacto']
            || $antes['urgencia'] !== $depois['urgencia'];
        $foiResolvido = $antes['status_glpi'] !== 'resolvido' && $depois['status_glpi'] === 'resolvido';
        $foiFechado   = $antes['status_glpi'] !== 'fechado' && $depois['status_glpi'] === 'fechado';

        $this->db->prepare(
            'UPDATE chamados SET impacto = :impacto, urgencia = :urgencia, criticidade = :criticidade,
                status_glpi = :status, tecnico_atual_id = :tecnico, prazo_resolucao = :prazo,
                resolvido_em = IF(:resolvido = 1, NOW(), resolvido_em),
                fechado_em = IF(:fechado = 1, NOW(), fechado_em)
             WHERE id = :id'
        )->execute([
            'impacto'    => $depois['impacto'],
            'urgencia'   => $depois['urgencia'],
            'criticidade'=> $depois['criticidade'],
            'status'     => $depois['status_glpi'],
            'tecnico'    => $depois['tecnico_atual_id'],
            'prazo'      => $depois['prazo_resolucao']->format('Y-m-d H:i:s'),
            'resolvido'  => $foiResolvido ? 1 : 0,
            'fechado'    => $foiFechado ? 1 : 0,
            'id'         => $chamadoId,
        ]);

        if ($mudouTecnico && $depois['tecnico_atual_id'] !== null && $antes['tecnico_atual_id'] === null) {
            $this->notificarNovoChamado($antes['glpi_ticket_id'], $depois['tecnico_atual_id'], atribuicaoDireta: true);
        } elseif ($mudouTecnico && $depois['tecnico_atual_id'] !== null) {
            $this->notificarReatribuicao($chamadoId, $antes['tecnico_atual_id'], $depois['tecnico_atual_id']);
        }

        if ($mudouCriticidade) {
            $this->notificarAlteracaoPrioridade($chamadoId, $antes, $depois);
        }

        if ($foiResolvido) {
            $this->notificarResolucao($chamadoId);
        }

        if ($foiFechado) {
            $this->notificarFechamento($chamadoId);
        }
    }

    // --- Métodos de mapeamento GLPI -> domínio (ajustar aos IDs reais do
    //     ambiente GLPI da NOVACAP durante a implantação) ---------------

    private function mapearImpacto(int $glpiImpact): string
    {
        return match ($glpiImpact) {
            5 => 'ALTISSIMO',
            4 => 'ALTO',
            3 => 'ELEVADO',
            2 => 'MEDIO',
            default => 'BAIXO',
        };
    }

    private function mapearUrgencia(int $glpiUrgency): string
    {
        return match ($glpiUrgency) {
            5, 4 => 'CRITICA',
            3 => 'ALTA',
            2 => 'MEDIA',
            default => 'BAIXA',
        };
    }

    private function mapearStatus(int $glpiStatus): string
    {
        // Status GLPI padrão: 1=novo,2=em atendimento(atribuído),
        // 3=em atendimento(planejado),4=pendente,5=solucionado,6=fechado
        return match ($glpiStatus) {
            1 => 'novo',
            2, 3 => 'atribuido',
            4 => 'pendente',
            5 => 'resolvido',
            6 => 'fechado',
            default => 'novo',
        };
    }

    private function equipeResponsavelPorGrupo(int $grupoId): string
    {
        $mapa = $this->config['grupos_glpi_para_equipe'] ?? [];
        return $mapa[$grupoId] ?? 'N1';
    }

    private function resolverTecnicoAtribuido(int $ticketId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM tecnicos WHERE glpi_user_id = (
            SELECT users_id FROM glpi_ticket_users_cache WHERE ticket_id = :tid LIMIT 1
        )');
        // Nota: em produção, usar GlpiClient::getTicketUsers($ticketId) em
        // vez de tabela de cache; simplificado aqui por clareza estrutural.
        $stmt->execute(['tid' => $ticketId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function buscarChamadoLocal(int $ticketId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM chamados WHERE glpi_ticket_id = :id');
        $stmt->execute(['id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function inserirChamado(
        array $ticket, int $ticketId, string $tipo, string $impacto, string $urgencia,
        string $criticidade, string $equipe, string $status, DateTimeImmutable $abertoEm,
        DateTimeImmutable $prazoInicio, DateTimeImmutable $prazoResolucao, ?int $tecnicoId
    ): void {
        $this->db->prepare(
            'INSERT INTO chamados
                (glpi_ticket_id, numero, titulo, tipo, categoria, solicitante_nome,
                 impacto, urgencia, criticidade, equipe_atual, tecnico_atual_id, status_glpi,
                 prazo_inicio_atendimento, prazo_resolucao, aberto_em)
             VALUES
                (:tid, :numero, :titulo, :tipo, :categoria, :solicitante,
                 :impacto, :urgencia, :criticidade, :equipe, :tecnico, :status,
                 :prazo_inicio, :prazo_resolucao, :aberto_em)'
        )->execute([
            'tid'         => $ticketId,
            'numero'      => (string) $ticketId,
            'titulo'      => $ticket['name'] ?? '(sem título)',
            'tipo'        => $tipo,
            'categoria'   => $ticket['itilcategories_id'] ?? null,
            'solicitante' => $ticket['users_id_recipient'] ?? null,
            'impacto'     => $impacto,
            'urgencia'    => $urgencia,
            'criticidade' => $criticidade,
            'equipe'      => $equipe,
            'tecnico'     => $tecnicoId,
            'status'      => $status,
            'prazo_inicio'    => $prazoInicio->format('Y-m-d H:i:s'),
            'prazo_resolucao' => $prazoResolucao->format('Y-m-d H:i:s'),
            'aberto_em'       => $abertoEm->format('Y-m-d H:i:s'),
        ]);
    }

    private function notificarNovoChamado(int $ticketId, ?int $tecnicoId, bool $atribuicaoDireta = false): void
    {
        // Implementação delega a montagem do Chamado + texto ao chamador
        // (ChamadoRepository::hidratar) — omitido aqui por brevidade de
        // amostra; ver src/Repositories/ChamadoRepository.php
    }

    private function notificarReatribuicao(int $chamadoId, ?int $tecnicoAnteriorId, int $tecnicoNovoId): void
    {
        // idem
    }

    private function notificarAlteracaoPrioridade(int $chamadoId, array $antes, array $depois): void
    {
        // idem
    }

    private function notificarResolucao(int $chamadoId): void
    {
        // idem
    }

    private function notificarFechamento(int $chamadoId): void
    {
        // idem
    }

    private function obterUltimoCheckpoint(): DateTimeImmutable
    {
        $stmt = $this->db->query("SELECT valor FROM sync_checkpoint WHERE chave = 'glpi_sync' LIMIT 1");
        $valor = $stmt->fetchColumn();
        return $valor ? new DateTimeImmutable($valor) : new DateTimeImmutable('-10 minutes');
    }

    private function atualizarCheckpoint(DateTimeImmutable $momento): void
    {
        $this->db->prepare(
            "INSERT INTO sync_checkpoint (chave, valor) VALUES ('glpi_sync', :v)
             ON DUPLICATE KEY UPDATE valor = :v"
        )->execute(['v' => $momento->format('Y-m-d H:i:s')]);
    }
}

<?php

namespace App\Workers;

use App\GLPI\GlpiClient;
use App\Models\Chamado;
use App\Services\SlaEngine;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use App\Support\GlpiUrl;
use App\Telegram\TelegramClient;
use PDO;
use DateTimeImmutable;

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
            $ticketId = (int) ($linha['2'] ?? $linha['id'] ?? 0);
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

        $impacto    = $this->mapearImpacto((int) ($ticket['impact'] ?? 3));
        $urgencia   = $this->mapearUrgencia((int) ($ticket['urgency'] ?? 3));
        $tipo       = ((int) ($ticket['type'] ?? 1)) === 2 ? 'REQUISICAO' : 'INCIDENTE';
        $criticidade = $this->mapearPrioridadeGlpi((int) ($ticket['priority'] ?? 3));

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
        $solicitanteNome = $this->resolverNomeSolicitante($ticket);
        $localizacao = $this->resolverLocalizacao($ticket);
        if (isset($ticket['itilcategories_id'])) {
            $ticket['itilcategories_id'] = html_entity_decode((string) $ticket['itilcategories_id'], ENT_QUOTES, 'UTF-8');
        }
        if (isset($ticket['name'])) {
            $ticket['name'] = html_entity_decode((string) $ticket['name'], ENT_QUOTES, 'UTF-8');
        }

        if ($existente === null) {
            $chamadoId = $this->inserirChamado($ticket, $ticketId, $tipo, $impacto, $urgencia, $criticidade,
                $equipe, $statusGlpi, $abertoEm, $prazoInicio, $prazoResolucao, $tecnicoAtualId, $localizacao);

            $this->notificarNovoChamado($chamadoId, $tecnicoAtualId);
            return;
        }

        $this->detectarEventosEAtualizar($existente, [
            'impacto'          => $impacto,
            'urgencia'         => $urgencia,
            'criticidade'      => $criticidade,
            'status_glpi'      => $statusGlpi,
            'tecnico_atual_id' => $tecnicoAtualId,
            'prazo_resolucao'  => $prazoResolucao,
            'ticket'           => $ticket,
            'titulo'           => $ticket['name'] ?? $existente['titulo'],
            'categoria'        => $ticket['itilcategories_id'] ?? $existente['categoria'],
            'solicitante_nome' => $solicitanteNome ?? $existente['solicitante_nome'],
            'unidade'          => $localizacao ?? $existente['unidade'],
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
                titulo = :titulo, categoria = :categoria, solicitante_nome = :solicitante,
                unidade = :unidade,
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
            'titulo'     => $depois['titulo'] ?? $antes['titulo'],
            'categoria'  => $depois['categoria'] ?? $antes['categoria'],
            'solicitante'=> $depois['solicitante_nome'] ?? $antes['solicitante_nome'],
            'unidade'    => $depois['unidade'] ?? $antes['unidade'],
            'resolvido'  => $foiResolvido ? 1 : 0,
            'fechado'    => $foiFechado ? 1 : 0,
            'id'         => $chamadoId,
        ]);

        if ($mudouTecnico && $depois['tecnico_atual_id'] !== null && $antes['tecnico_atual_id'] === null) {
            $this->notificarNovoChamado($chamadoId, $depois['tecnico_atual_id'], atribuicaoDireta: true);
        } elseif ($mudouTecnico && $depois['tecnico_atual_id'] !== null) {
            $this->notificarReatribuicao($chamadoId, $antes['tecnico_atual_id'] ? (int) $antes['tecnico_atual_id'] : null, (int) $depois['tecnico_atual_id']);
        }

        if ($mudouCriticidade) {
            $this->notificarAlteracaoPrioridade($chamadoId, $antes, $depois);
        }

        if ($foiResolvido) {
            $this->notificarResolucao($chamadoId, $depois['ticket'] ?? []);
        }

        if ($foiFechado) {
            $this->notificarFechamento($chamadoId);
        }
    }

    private function notificarNovoChamado(int $chamadoId, ?int $tecnicoId, bool $atribuicaoDireta = false): void
    {
        $chamado = $this->hidratarChamado($chamadoId);
        if ($chamado === null) {
            return;
        }

        if ($tecnicoId === null) {
            // Política acordada com a NOVACAP (jul/2026): o grupo só recebe
            // alerta de chamados novos com prioridade Média, Alta ou Crítica.
            // Chamados de prioridade Baixa não geram notificação no grupo.
            if ($chamado->criticidade === 'BAIXA') {
                return;
            }

            $this->dispatcher->enfileirar(
                $chamadoId,
                $this->chatGrupoPorEquipe($chamado->equipeAtual, 'novo_chamado'),
                'novo_chamado',
                $this->formatter->novoChamado($chamado)
            );
            return;
        }

        $tecnico = $this->buscarTecnico($tecnicoId);
        if ($tecnico === null) {
            return;
        }

        $texto = $this->formatter->chamadoAtribuido($chamado, $tecnico['nome']);

        $this->dispatcher->enfileirar(
            $chamadoId,
            (int) $tecnico['telegram_chat_id'],
            'atribuicao',
            $texto,
            TelegramClient::tecladoAcoesChamado($chamadoId, $chamado->linkGlpi)
        );
    }

    private function notificarReatribuicao(int $chamadoId, ?int $tecnicoAnteriorId, int $tecnicoNovoId): void
    {
        $chamado = $this->hidratarChamado($chamadoId);
        if ($chamado === null) {
            return;
        }

        $tecnicoNovo = $this->buscarTecnico($tecnicoNovoId);
        if ($tecnicoNovo === null) {
            return;
        }

        $tecnicoAnterior = $tecnicoAnteriorId ? $this->buscarTecnico($tecnicoAnteriorId) : null;
        $nomeAnterior = $tecnicoAnterior['nome'] ?? '(sem tecnico anterior)';

        $this->db->prepare(
            'INSERT INTO chamado_reatribuicoes (chamado_id, tecnico_anterior_id, tecnico_novo_id)
             VALUES (:chamado_id, :anterior, :novo)'
        )->execute([
            'chamado_id' => $chamadoId,
            'anterior'   => $tecnicoAnteriorId,
            'novo'       => $tecnicoNovoId,
        ]);

        $this->dispatcher->enfileirar(
            $chamadoId,
            (int) $tecnicoNovo['telegram_chat_id'],
            'reatribuicao',
            $this->formatter->reatribuicao($chamado, $nomeAnterior, $tecnicoNovo['nome'], null),
            TelegramClient::tecladoAcoesChamado($chamadoId, $chamado->linkGlpi)
        );

        if ($tecnicoAnterior !== null) {
            $this->dispatcher->enfileirar(
                $chamadoId,
                (int) $tecnicoAnterior['telegram_chat_id'],
                'reatribuicao_anterior',
                "Info: O chamado #{$chamado->numero} foi reatribuido para {$tecnicoNovo['nome']}."
            );
        }
    }

    private function notificarAlteracaoPrioridade(int $chamadoId, array $antes, array $depois): void
    {
        $chamado = $this->hidratarChamado($chamadoId);
        if ($chamado === null) {
            return;
        }

        $texto = $this->formatter->alteracaoPrioridade($chamado, [
            'urgencia'    => $antes['urgencia'],
            'impacto'     => $antes['impacto'],
            'criticidade' => $antes['criticidade'],
        ]);

        $destino = $chamado->tecnicoAtualId !== null
            ? $this->chatDoTecnico($chamado->tecnicoAtualId)
            : $this->chatGrupoPorEquipe($chamado->equipeAtual, 'novo_chamado');

        if ($destino !== null) {
            $this->dispatcher->enfileirar($chamadoId, $destino, 'alteracao_prioridade', $texto);
        }
    }

    private function notificarResolucao(int $chamadoId, array $ticket): void
    {
        $chamado = $this->hidratarChamado($chamadoId);
        if ($chamado === null) {
            return;
        }

        $tempoGastoMinutos = (int) ((time() - $chamado->abertoEm->getTimestamp()) / 60);
        $nomeResolvedor = '-';

        if ($chamado->tecnicoAtualId !== null) {
            $tecnico = $this->buscarTecnico($chamado->tecnicoAtualId);
            $nomeResolvedor = $tecnico['nome'] ?? '-';
        }

        $destino = $chamado->tecnicoAtualId !== null
            ? $this->chatDoTecnico($chamado->tecnicoAtualId)
            : $this->chatGrupoPorEquipe($chamado->equipeAtual, 'novo_chamado');

        if ($destino !== null) {
            $this->dispatcher->enfileirar(
                $chamadoId,
                $destino,
                'resolvido',
                $this->formatter->chamadoResolvido($chamado, $nomeResolvedor, $tempoGastoMinutos)
            );
        }
    }

    private function notificarFechamento(int $chamadoId): void
    {
        $chamado = $this->hidratarChamado($chamadoId);
        if ($chamado === null) {
            return;
        }

        $destino = $chamado->tecnicoAtualId !== null
            ? $this->chatDoTecnico($chamado->tecnicoAtualId)
            : $this->chatGrupoPorEquipe($chamado->equipeAtual, 'novo_chamado');

        if ($destino !== null) {
            $this->dispatcher->enfileirar($chamadoId, $destino, 'fechado', $this->formatter->chamadoFechado($chamado));
        }
    }

    private function hidratarChamado(int $chamadoId): ?Chamado
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

    private function buscarTecnico(int $tecnicoId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tecnicos WHERE id = :id AND ativo = 1');
        $stmt->execute(['id' => $tecnicoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function chatDoTecnico(int $tecnicoId): ?int
    {
        $tecnico = $this->buscarTecnico($tecnicoId);
        return $tecnico ? (int) $tecnico['telegram_chat_id'] : null;
    }

    private function chatGrupoPorEquipe(string $equipe, string $tipoGrupo): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT chat_id FROM grupos_telegram
             WHERE (equipe_vinculada = :equipe OR tipo = :tipo) AND ativo = 1
             ORDER BY (equipe_vinculada = :equipe2) DESC
             LIMIT 1"
        );
        $stmt->execute(['equipe' => $equipe, 'tipo' => $tipoGrupo, 'equipe2' => $equipe]);
        $chatId = $stmt->fetchColumn();

        if ($chatId !== false) {
            return (int) $chatId;
        }

        $fallback = getenv('TELEGRAM_GRUPO_NOVO_CHAMADO_ID');
        return $fallback ? (int) $fallback : null;
    }

    private function mapearPrioridadeGlpi(int $glpiPriority): string
    {
        return match ($glpiPriority) {
            6 => 'CRITICA',
            5, 4 => 'ALTA',
            3 => 'MEDIA',
            default => 'BAIXA',
        };
    }

    private function resolverNomeSolicitante(array $ticket): ?string
    {
        $recipientId = (int) ($ticket['users_id_recipient'] ?? 0);
        if ($recipientId <= 0) {
            return null;
        }

        try {
            $user = $this->glpi->getUser($recipientId);
        } catch (\Throwable $e) {
            return null;
        }

        $nome = trim(($user['firstname'] ?? '') . ' ' . ($user['realname'] ?? ''));
        return $nome !== '' ? $nome : ($user['name'] ?? null);
    }

    /**
     * Resolve o nome da localização do chamado. Como getTicket() já é
     * chamado com expand_dropdowns=true, o campo locations_id já vem como
     * texto (nome/sigla da localidade), não como ID numérico.
     */
    private function resolverLocalizacao(array $ticket): ?string
    {
        $valor = $ticket['locations_id'] ?? null;

        if ($valor === null || $valor === '' || $valor === '0') {
            return null;
        }

        return html_entity_decode((string) $valor, ENT_QUOTES, 'UTF-8');
    }

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
        try {
            $vinculos = $this->glpi->getTicketUsers($ticketId);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($vinculos as $vinculo) {
            if ((int) ($vinculo['type'] ?? 0) === 2 && !empty($vinculo['users_id'])) {
                $stmt = $this->db->prepare('SELECT id FROM tecnicos WHERE glpi_user_id = :uid AND ativo = 1');
                $stmt->execute(['uid' => (int) $vinculo['users_id']]);
                $id = $stmt->fetchColumn();
                return $id !== false ? (int) $id : null;
            }
        }

        return null;
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
        DateTimeImmutable $prazoInicio, DateTimeImmutable $prazoResolucao, ?int $tecnicoId,
        ?string $localizacao = null
    ): int {
        $this->db->prepare(
            'INSERT INTO chamados
                (glpi_ticket_id, numero, titulo, tipo, categoria, solicitante_nome, unidade,
                 impacto, urgencia, criticidade, equipe_atual, tecnico_atual_id, status_glpi,
                 prazo_inicio_atendimento, prazo_resolucao, aberto_em)
             VALUES
                (:tid, :numero, :titulo, :tipo, :categoria, :solicitante, :unidade,
                 :impacto, :urgencia, :criticidade, :equipe, :tecnico, :status,
                 :prazo_inicio, :prazo_resolucao, :aberto_em)'
        )->execute([
            'tid'         => $ticketId,
            'numero'      => (string) $ticketId,
            'titulo'      => $ticket['name'] ?? '(sem titulo)',
            'tipo'        => $tipo,
            'categoria'   => $ticket['itilcategories_id'] ?? null,
            'solicitante' => $this->resolverNomeSolicitante($ticket),
            'unidade'     => $localizacao,
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

        return (int) $this->db->lastInsertId();
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

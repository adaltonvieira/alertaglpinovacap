<?php

namespace App\Workers;

use App\Models\Chamado;
use App\Services\SlaEngine;
use App\Services\MessageFormatter;
use App\Services\NotificationDispatcher;
use PDO;
use DateTimeImmutable;

/**
 * Worker executado a cada 1 minuto (ver docker/crontab) responsÃ¡vel por:
 *  - Detectar chamados crÃ­ticos sem tÃ©cnico atribuÃ­do hÃ¡ tempo excessivo
 *    (escalonamento automÃ¡tico â€” TR item 3.17 / cumprimento de NMS).
 *  - Disparar alertas de aproximaÃ§Ã£o de vencimento (50/75/90/95% do prazo).
 *  - Disparar alerta de SLA vencido e lembretes periÃ³dicos enquanto
 *    permanecer vencido (15min / 30min / 1h, conforme especificado).
 *
 * Todos os limiares vÃªm de config/sla.php, refletindo o TR.
 */
class SlaMonitorWorker
{
    public function __construct(
        private PDO $db,
        private SlaEngine $sla,
        private MessageFormatter $formatter,
        private NotificationDispatcher $dispatcher,
    ) {
    }

    public function executar(): void
    {
        $this->verificarAproximacaoVencimento();
        $this->verificarVencidos();
        $this->verificarEscalonamentoSemAtribuicao();
    }

    private function verificarAproximacaoVencimento(): void
    {
        foreach ($this->chamadosAbertos() as $row) {
            $chamado = $this->hidratar($row);
            $percentual = $chamado->percentualSlaConsumido();

            foreach ($this->sla->limiaresAlertaPercentual() as $limiar) {
                if ($percentual >= $limiar && !$chamado->estaVencido()) {
                    $this->dispatcher->enfileirar(
                        $chamado->id,
                        $this->chatDoTecnicoOuGrupo($chamado),
                        "vencimento_alerta_{$limiar}",
                        $this->formatter->alertaVencimento($chamado, $percentual)
                    );
                }
            }

            // ReforÃ§o nos Ãºltimos minutos absolutos, independente do %
            $minutosRestantes = (int) (($chamado->prazoResolucao->getTimestamp() - time()) / 60);
            foreach ($this->sla->minutosFinaisAlerta() as $minutosAlerta) {
                if ($minutosRestantes > 0 && $minutosRestantes <= $minutosAlerta) {
                    $this->dispatcher->enfileirar(
                        $chamado->id,
                        $this->chatDoTecnicoOuGrupo($chamado),
                        "vencimento_final_{$minutosAlerta}",
                        $this->formatter->alertaVencimento($chamado, $percentual)
                    );
                }
            }
        }
    }

    private function verificarVencidos(): void
    {
        foreach ($this->chamadosVencidosNaoNotificados() as $row) {
            $chamado = $this->hidratar($row);

            $this->db->prepare(
                'UPDATE chamados SET sla_violado = 1, sla_violado_em = NOW() WHERE id = :id'
            )->execute(['id' => $chamado->id]);

            $this->dispatcher->enfileirar(
                $chamado->id,
                $this->chatDoTecnicoOuGrupo($chamado),
                'vencido',
                $this->formatter->chamadoVencido($chamado)
            );
        }

        // Lembretes recorrentes para chamados jÃ¡ vencidos e ainda abertos
        foreach ($this->chamadosVencidosAbertos() as $row) {
            $chamado = $this->hidratar($row);
            $minutosVencido = (int) ((time() - $chamado->prazoResolucao->getTimestamp()) / 60);

            $cadencia = $this->sla->cadenciaLembretePosVencimentoMinutos();
            $ultimaCadencia = end($cadencia); // 60min = cadÃªncia estÃ¡vel apÃ³s o Ãºltimo marco

            $deveNotificar = in_array($minutosVencido, $cadencia, true)
                || ($minutosVencido > $ultimaCadencia && $minutosVencido % $ultimaCadencia === 0);

            if ($deveNotificar) {
                $this->dispatcher->enfileirar(
                    $chamado->id,
                    $this->chatDoTecnicoOuGrupo($chamado),
                    "lembrete_vencido_{$minutosVencido}",
                    $this->formatter->lembreteVencido($chamado, $minutosVencido)
                );
            }
        }
    }

    /**
     * Escalonamento automÃ¡tico: chamado crÃ­tico sem tÃ©cnico atribuÃ­do por
     * tempo excessivo Ã© encaminhado ao grupo "Novo Chamado" com destaque e,
     * opcionalmente, ao supervisor da equipe responsÃ¡vel.
     */
    private function verificarEscalonamentoSemAtribuicao(): void
    {
        $stmt = $this->db->query(
            "SELECT * FROM chamados
             WHERE tecnico_atual_id IS NULL AND status_glpi = 'novo'"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chamado = $this->hidratar($row);
            $minutosSemAtribuicao = (int) ((time() - $chamado->abertoEm->getTimestamp()) / 60);
            $limiar = $this->sla->minutosParaEscalonamentoSemAtribuicao($chamado->criticidade);

            if ($minutosSemAtribuicao >= $limiar) {
                $this->dispatcher->enfileirar(
                    $chamado->id,
                    $this->chatGrupoEscalonamento($chamado),
                    'escalonamento_sem_atribuicao',
                    "â« <b>ESCALONAMENTO â€” SEM TÃ‰CNICO ATRIBUÃDO</b>\n\n" .
                    "Chamado #{$chamado->numero} estÃ¡ hÃ¡ {$minutosSemAtribuicao}min sem atribuiÃ§Ã£o " .
                    "(limite para prioridade {$this->sla->labelCriticidade($chamado->criticidade)}: {$limiar}min).\n\n" .
                    "Link: {$chamado->linkGlpi}"
                );
            }
        }
    }

    private function chamadosAbertos(): array
    {
        $stmt = $this->db->query(
            "SELECT c.*, l.nome AS localidade_nome
             FROM chamados c
             LEFT JOIN localidades l ON l.id = c.localidade_id
             WHERE c.status_glpi NOT IN ('resolvido','fechado')"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function chamadosVencidosNaoNotificados(): array
    {
        $stmt = $this->db->query(
            "SELECT c.*, l.nome AS localidade_nome
             FROM chamados c
             LEFT JOIN localidades l ON l.id = c.localidade_id
             WHERE c.status_glpi NOT IN ('resolvido','fechado')
               AND c.prazo_resolucao < NOW()
               AND c.sla_violado = 0"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function chamadosVencidosAbertos(): array
    {
        $stmt = $this->db->query(
            "SELECT c.*, l.nome AS localidade_nome
             FROM chamados c
             LEFT JOIN localidades l ON l.id = c.localidade_id
             WHERE c.status_glpi NOT IN ('resolvido','fechado')
               AND c.sla_violado = 1"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hidratar(array $row): Chamado
    {
        $row['link_glpi'] = GlpiUrl::ticketLink((int) $row['glpi_ticket_id']);
        return Chamado::fromArray($row);
    }

    private function chatDoTecnicoOuGrupo(Chamado $c): int|string
    {
        if ($c->tecnicoAtualId === null) {
            return $this->chatGrupoEscalonamento($c);
        }

        $stmt = $this->db->prepare('SELECT telegram_chat_id FROM tecnicos WHERE id = :id');
        $stmt->execute(['id' => $c->tecnicoAtualId]);
        return (int) $stmt->fetchColumn();
    }

    private function chatGrupoEscalonamento(Chamado $c): int|string
    {
        $stmt = $this->db->prepare(
            "SELECT chat_id FROM grupos_telegram
             WHERE equipe_vinculada = :equipe AND ativo = 1 LIMIT 1"
        );
        $stmt->execute(['equipe' => $c->equipeAtual]);
        $chatId = $stmt->fetchColumn();
        return $chatId !== false ? (int) $chatId : (int) getenv('TELEGRAM_GRUPO_NOVO_CHAMADO_ID');
    }
}

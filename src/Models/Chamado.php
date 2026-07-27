<?php

namespace App\Models;

use DateTimeImmutable;

class Chamado
{
    public function __construct(
        public int $id,
        public int $glpiTicketId,
        public string $numero,
        public string $titulo,
        public string $tipo,          // INCIDENTE | REQUISICAO
        public string $categoria,
        public string $solicitanteNome,
        public ?string $localidadeNome,
        public ?string $unidade,
        public string $impacto,
        public string $urgencia,
        public string $criticidade,
        public string $equipeAtual,
        public ?int $tecnicoAtualId,
        public string $statusGlpi,
        public DateTimeImmutable $abertoEm,
        public DateTimeImmutable $prazoInicioAtendimento,
        public DateTimeImmutable $prazoResolucao,
        public ?DateTimeImmutable $atribuidoEm = null,
        public ?DateTimeImmutable $resolvidoEm = null,
        public ?DateTimeImmutable $fechadoEm = null,
        public bool $slaViolado = false,
        public string $linkGlpi = '',
    ) {
    }

    public static function fromArray(array $row): self
    {
        $parseDate = fn(?string $v) => $v ? new DateTimeImmutable($v) : null;

        return new self(
            id: (int) $row['id'],
            glpiTicketId: (int) $row['glpi_ticket_id'],
            numero: $row['numero'],
            titulo: $row['titulo'],
            tipo: $row['tipo'],
            categoria: $row['categoria'] ?? '-',
            solicitanteNome: $row['solicitante_nome'] ?? '-',
            localidadeNome: $row['localidade_nome'] ?? null,
            unidade: $row['unidade'] ?? null,
            impacto: $row['impacto'],
            urgencia: $row['urgencia'],
            criticidade: $row['criticidade'],
            equipeAtual: $row['equipe_atual'],
            tecnicoAtualId: $row['tecnico_atual_id'] !== null ? (int) $row['tecnico_atual_id'] : null,
            statusGlpi: $row['status_glpi'],
            abertoEm: $parseDate($row['aberto_em']),
            prazoInicioAtendimento: $parseDate($row['prazo_inicio_atendimento']),
            prazoResolucao: $parseDate($row['prazo_resolucao']),
            atribuidoEm: $parseDate($row['atribuido_em'] ?? null),
            resolvidoEm: $parseDate($row['resolvido_em'] ?? null),
            fechadoEm: $parseDate($row['fechado_em'] ?? null),
            slaViolado: (bool) ($row['sla_violado'] ?? false),
            linkGlpi: $row['link_glpi'] ?? '',
        );
    }

    public function percentualSlaConsumido(): int
    {
        $inicio = $this->abertoEm->getTimestamp();
        $fim = $this->prazoResolucao->getTimestamp();
        $agora = time();

        if ($fim <= $inicio) {
            return 100;
        }

        $percentual = (($agora - $inicio) / ($fim - $inicio)) * 100;

        return (int) max(0, min(100, round($percentual)));
    }

    public function estaVencido(): bool
    {
        return time() > $this->prazoResolucao->getTimestamp();
    }
}

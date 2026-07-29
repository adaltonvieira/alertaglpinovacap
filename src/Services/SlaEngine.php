<?php

namespace App\Services;

use DateTimeImmutable;
use DateInterval;

class SlaEngine
{
    private array $sla;

    public function __construct(?array $slaConfig = null)
    {
        $this->sla = $slaConfig ?? require dirname(__DIR__, 2) . '/config/sla.php';
    }

    public function prazoInicioAtendimentoMinutos(string $criticidade): int
    {
        return $this->sla['criticidade_atendimento'][$criticidade]['prazo_inicio_minutos']
            ?? $this->sla['criticidade_atendimento']['MEDIA']['prazo_inicio_minutos'];
    }

    public function prazoResolucaoMinutos(string $tipo, string $criticidade): int
    {
        if ($tipo === 'INCIDENTE') {
            return $this->sla['incidente_prazo_maximo'][$criticidade]
                ?? $this->sla['incidente_prazo_maximo']['MEDIA'];
        }

        return $this->sla['requisicao_sla'][$criticidade]['prazo_maximo_min']
            ?? $this->sla['requisicao_sla']['MEDIA']['prazo_maximo_min'];
    }

    public function tempoMedioEsperadoMinutos(string $criticidade): ?int
    {
        return $this->sla['requisicao_sla'][$criticidade]['tempo_medio_min'] ?? null;
    }

    public function calcularCriticidade(string $impacto, string $urgencia): string
    {
        $pesoImpacto  = $this->sla['impacto'][$impacto]['peso']  ?? 2;
        $pesoUrgencia = $this->sla['urgencia'][$urgencia]['peso'] ?? 2;

        $score = ($pesoImpacto * 1.0 + $pesoUrgencia * 1.5) / 2.5;

        return match (true) {
            $score >= 4.0 => 'CRITICA',
            $score >= 3.0 => 'ALTA',
            $score >= 2.0 => 'MEDIA',
            default        => 'BAIXA',
        };
    }

    public function calcularPrazoLimite(
        DateTimeImmutable $inicio,
        int $minutos,
        string $equipe
    ): DateTimeImmutable {
        $janela = $this->janelaParaEquipe($equipe);

        if (!$this->sla['contar_prazo_apenas_horario_util'] || ($janela['24x7'] ?? false)) {
            return $inicio->add(new DateInterval('PT' . $minutos . 'M'));
        }

        return $this->somarMinutosUteis($inicio, $minutos, $janela);
    }

    private function janelaParaEquipe(string $equipe): array
    {
        return match ($equipe) {
            'N3'  => $this->sla['janelas_atendimento']['N3'],
            'NOC' => $this->sla['janelas_atendimento']['NOC'],
            default => $this->sla['janelas_atendimento']['N1_N2'],
        };
    }

    private function somarMinutosUteis(
        DateTimeImmutable $inicio,
        int $minutosRestantes,
        array $janela
    ): DateTimeImmutable {
        $cursor = $inicio;
        $diasUteis = $janela['dias_uteis'];

        while ($minutosRestantes > 0) {
            $diaSemana = strtolower($cursor->format('D'));
            $diaSemana = substr($diaSemana, 0, 3);

            if (!in_array($diaSemana, $diasUteis, true)) {
                $cursor = $cursor->modify('+1 day')->setTime(
                    (int) explode(':', $janela['inicio'])[0],
                    (int) explode(':', $janela['inicio'])[1]
                );
                continue;
            }

            [$hIni, $mIni] = array_map('intval', explode(':', $janela['inicio']));
            [$hFim, $mFim] = array_map('intval', explode(':', $janela['fim']));

            $inicioExpediente = $cursor->setTime($hIni, $mIni);
            $fimExpediente    = $cursor->setTime($hFim, $mFim);

            if ($cursor < $inicioExpediente) {
                $cursor = $inicioExpediente;
            }

            if ($cursor >= $fimExpediente) {
                $cursor = $cursor->modify('+1 day')->setTime($hIni, $mIni);
                continue;
            }

            $minutosDisponiveisHoje = (int) floor(($fimExpediente->getTimestamp() - $cursor->getTimestamp()) / 60);

            if ($minutosRestantes <= $minutosDisponiveisHoje) {
                return $cursor->modify('+' . (int) $minutosRestantes . ' minutes');
            }

            $minutosRestantes -= $minutosDisponiveisHoje;
            $cursor = $cursor->modify('+1 day')->setTime($hIni, $mIni);
        }

        return $cursor;
    }

    public function limiaresAlertaPercentual(): array
    {
        return $this->sla['alertas_vencimento']['percentuais'];
    }

    public function minutosFinaisAlerta(): array
    {
        return $this->sla['alertas_vencimento']['minutos_finais'];
    }

    public function cadenciaLembretePosVencimentoMinutos(): array
    {
        return $this->sla['lembrete_pos_vencimento_minutos'];
    }

    public function minutosParaEscalonamentoSemAtribuicao(string $criticidade): int
    {
        return $this->sla['escalonamento']['sem_atribuicao_minutos'][$criticidade] ?? 30;
    }

    public function emojiCriticidade(string $criticidade): string
    {
        return $this->sla['criticidade_atendimento'][$criticidade]['emoji'] ?? '?';
    }

    public function emojiImpacto(string $impacto): string
    {
        return $this->sla['impacto'][$impacto]['emoji'] ?? '?';
    }

    public function emojiUrgencia(string $urgencia): string
    {
        return $this->sla['urgencia'][$urgencia]['emoji'] ?? '?';
    }

    public function labelCriticidade(string $criticidade): string
    {
        return $this->sla['criticidade_atendimento'][$criticidade]['label'] ?? $criticidade;
    }
}

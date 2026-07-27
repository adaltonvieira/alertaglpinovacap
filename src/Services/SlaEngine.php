<?php

namespace App\Services;

use DateTimeImmutable;
use DateInterval;

/**
 * Motor de SLA — calcula prazos de atendimento e resolução de chamados
 * com base exclusivamente nos parâmetros do config/sla.php, que por sua vez
 * refletem o Termo de Referência NOVACAP (ANEXO III e ANEXO IV).
 *
 * Regras aplicadas (com referência ao TR):
 *  - TR ANEXO IV Tabela XVI: prazo de INÍCIO de atendimento por criticidade.
 *  - TR ANEXO IV Tabela XIX: prazo máximo de RESOLUÇÃO de incidentes.
 *  - TR ANEXO IV Tabela XX: tempo médio e prazo máximo de requisições.
 *  - TR ANEXO IV item 3.11: prazos contados em horas úteis (dentro da janela
 *    de atendimento aplicável à equipe responsável).
 */
class SlaEngine
{
    private array $sla;

    public function __construct(?array $slaConfig = null)
    {
        $this->sla = $slaConfig ?? require dirname(__DIR__, 2) . '/config/sla.php';
    }

    /**
     * Retorna o prazo (em minutos) para INÍCIO de atendimento, conforme
     * TR ANEXO IV Tabela XVI.
     */
    public function prazoInicioAtendimentoMinutos(string $criticidade): int
    {
        return $this->sla['criticidade_atendimento'][$criticidade]['prazo_inicio_minutos']
            ?? $this->sla['criticidade_atendimento']['MEDIA']['prazo_inicio_minutos'];
    }

    /**
     * Retorna o prazo (em minutos) para RESOLUÇÃO, diferenciando
     * Incidente (Tabela XIX) de Requisição (Tabela XX - prazo máximo).
     */
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

    /**
     * Calcula a criticidade "consolidada" do chamado a partir da matriz
     * Impacto x Urgência (TR ANEXO IV item 6.10.6 - Matriz de Prioridade).
     * Implementação: maior peso relativo entre impacto e urgência define a
     * criticidade, com regra de corte alinhada às 4 faixas do TR
     * (Crítica/Alta/Média/Baixa) usadas nas tabelas de NMS.
     */
    public function calcularCriticidade(string $impacto, string $urgencia): string
    {
        $pesoImpacto  = $this->sla['impacto'][$impacto]['peso']  ?? 2;
        $pesoUrgencia = $this->sla['urgencia'][$urgencia]['peso'] ?? 2;

        // Média ponderada (urgência pesa um pouco mais, pois define o tempo
        // de restabelecimento exigido pelo negócio — TR item 6.9.2)
        $score = ($pesoImpacto * 1.0 + $pesoUrgencia * 1.5) / 2.5;

        return match (true) {
            $score >= 4.0 => 'CRITICA',
            $score >= 3.0 => 'ALTA',
            $score >= 2.0 => 'MEDIA',
            default        => 'BAIXA',
        };
    }

    /**
     * Calcula o instante-limite (DateTimeImmutable) considerando se o
     * prazo deve ser contado em horas úteis (TR item 3.11) ou em regime
     * contínuo (24x7, aplicável à NOC — TR § 10.3).
     */
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

    /**
     * Soma minutos respeitando apenas os intervalos de dias úteis/horário
     * definidos na janela de atendimento (simulação minuto a minuto de
     * forma otimizada por blocos de expediente).
     */
    private function somarMinutosUteis(
        DateTimeImmutable $inicio,
        int $minutosRestantes,
        array $janela
    ): DateTimeImmutable {
        $cursor = $inicio;
        $diasUteis = $janela['dias_uteis'];

        while ($minutosRestantes > 0) {
            $diaSemana = strtolower($cursor->format('D'));
            $diaSemana = substr($diaSemana, 0, 3); // mon, tue, wed...

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

            $minutosDisponiveisHoje = ($fimExpediente->getTimestamp() - $cursor->getTimestamp()) / 60;

            if ($minutosRestantes <= $minutosDisponiveisHoje) {
                return $cursor->modify("+{$minutosRestantes} minutes");
            }

            $minutosRestantes -= $minutosDisponiveisHoje;
            $cursor = $cursor->modify('+1 day')->setTime($hIni, $mIni);
        }

        return $cursor;
    }

    /**
     * Retorna os percentuais/limiares configurados para alertas de
     * aproximação de vencimento (não literal do TR, mas necessário para
     * operacionalizar o cumprimento das metas do TR item 3.17).
     */
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

    /** Emoji/label helpers usados na formatação das mensagens do Telegram */
    public function emojiCriticidade(string $criticidade): string
    {
        return $this->sla['criticidade_atendimento'][$criticidade]['emoji'] ?? '⚪';
    }

    public function emojiImpacto(string $impacto): string
    {
        return $this->sla['impacto'][$impacto]['emoji'] ?? '⚪';
    }

    public function emojiUrgencia(string $urgencia): string
    {
        return $this->sla['urgencia'][$urgencia]['emoji'] ?? '⚪';
    }

    public function labelCriticidade(string $criticidade): string
    {
        return $this->sla['criticidade_atendimento'][$criticidade]['label'] ?? $criticidade;
    }
}

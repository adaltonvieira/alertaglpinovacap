<?php

namespace App\Services;

use App\Models\Chamado;

class MessageFormatter
{
    public function __construct(private SlaEngine $sla)
    {
    }

    public function novoChamado(Chamado $c): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);

        return
            "\u{1f6a8} <b>NOVO CHAMADO</b>\n\n" .
            "Chamado: <b>#{$c->numero}</b>\n\n" .
            "T\u{ed}tulo:\n{$c->titulo}\n\n" .
            "Categoria:\n" . ($c->categoria ?: '-') . "\n\n" .
            "Solicitante:\n" . ($c->solicitanteNome ?: '-') . "\n\n" .
            "Data e hora:\n" . $c->abertoEm->format('d/m/Y H:i') . "\n\n" .
            "Prioridade:\n{$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "SLA (NMS conforme Termo de Refer\u{ea}ncia):\n" . $this->descricaoPrazoResolucao($c) . "\n\n" .
            "Tempo restante:\n{$tempoRestante}\n\n" .
            "Clique para abrir:\n{$c->linkGlpi}";
    }

    public function chamadoAtribuido(Chamado $c, string $nomeTecnico): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);

        return
            "\u{1f4cc} <b>CHAMADO ATRIBU\u{cd}DO A VOC\u{ca}</b>\n\n" .
            "T\u{e9}cnico: <b>{$nomeTecnico}</b>\n" .
            "Chamado: #{$c->numero}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n" .
            "SLA: " . $this->descricaoPrazoResolucao($c) . "\n" .
            "Tempo restante: {$tempoRestante}\n" .
            "Categoria: {$c->categoria}\n" .
            "Solicitante: {$c->solicitanteNome}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function reatribuicao(Chamado $c, string $tecnicoAnterior, string $tecnicoNovo, ?string $motivo): string
    {
        $msg =
            "\u{1f504} <b>CHAMADO REATRIBU\u{cd}DO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "T\u{e9}cnico anterior: {$tecnicoAnterior}\n" .
            "Novo t\u{e9}cnico: {$tecnicoNovo}\n" .
            "Data: " . (new \DateTimeImmutable())->format('d/m/Y H:i') . "\n";

        if ($motivo) {
            $msg .= "Motivo: {$motivo}\n";
        }

        return $msg . "\nLink: {$c->linkGlpi}";
    }

    public function alteracaoPrioridade(Chamado $c, array $antes): string
    {
        return
            "\u{26a0}\u{fe0f} <b>ALTERA\u{c7}\u{c3}O DE PRIORIDADE</b>\n\n" .
            "Chamado: #{$c->numero}\n\n" .
            "Antes:\n" .
            "  Urg\u{ea}ncia: {$antes['urgencia']}\n" .
            "  Impacto: {$antes['impacto']}\n" .
            "  Prioridade: {$antes['criticidade']}\n\n" .
            "Agora:\n" .
            "  Urg\u{ea}ncia: {$this->sla->emojiUrgencia($c->urgencia)} {$c->urgencia}\n" .
            "  Impacto: {$this->sla->emojiImpacto($c->impacto)} {$c->impacto}\n" .
            "  Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$c->criticidade}\n\n" .
            "Novo SLA: " . $this->descricaoPrazoResolucao($c) . "\n" .
            "Link: {$c->linkGlpi}";
    }

    public function alertaVencimento(Chamado $c, int $percentualConsumido): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);
        $emoji = $percentualConsumido >= 90 ? "\u{1f534}" : ($percentualConsumido >= 75 ? "\u{1f7e0}" : "\u{1f7e1}");

        return
            "{$emoji} <b>ATEN\u{c7}\u{c3}O \u{2014} SLA PR\u{d3}XIMO DO VENCIMENTO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "J\u{e1} consumido: {$percentualConsumido}% do prazo\n" .
            "Tempo restante: {$tempoRestante}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoVencido(Chamado $c): string
    {
        return
            "\u{1f534}\u{1f525} <b>CHAMADO COM SLA VENCIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Prazo previsto: {$c->prazoResolucao->format('d/m/Y H:i')}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "\u{26a0}\u{fe0f} Este chamado est\u{e1} fora do NMS definido no Termo de Refer\u{ea}ncia.\n" .
            "Link: {$c->linkGlpi}";
    }

    public function lembreteVencido(Chamado $c, int $minutosVencido): string
    {
        return
            "\u{1f534} <b>LEMBRETE \u{2014} CHAMADO AINDA VENCIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Vencido h\u{e1}: " . $this->formatarMinutos($minutosVencido) . "\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoResolvido(Chamado $c, string $resolvidoPor, int $tempoGastoMinutos): string
    {
        $prazoMin = $this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade);
        $situacao = $tempoGastoMinutos <= $prazoMin ? "\u{2705} Dentro do SLA" : "\u{26a0}\u{fe0f} Fora do SLA";

        return
            "\u{2705} <b>CHAMADO RESOLVIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Resolvido por: {$resolvidoPor}\n" .
            "Tempo gasto: " . $this->formatarMinutos($tempoGastoMinutos) . "\n" .
            "SLA previsto: " . $this->formatarMinutos($prazoMin) . "\n" .
            "Situa\u{e7}\u{e3}o: {$situacao}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoFechado(Chamado $c): string
    {
        return "\u{1f512} <b>CHAMADO ENCERRADO</b>\n\nChamado: #{$c->numero}\nLink: {$c->linkGlpi}";
    }

    private function descricaoPrazoResolucao(Chamado $c): string
    {
        $minutos = $this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade);
        return $this->formatarMinutos($minutos) .
            " (NMS " . ($c->tipo === 'INCIDENTE' ? "Requisi\u{e7}\u{e3}o" : 'Incidente') .
            " / TR ANEXO IV)";
    }

    private function formatarTempoRestante(\DateTimeImmutable $prazo): string
    {
        $agora = new \DateTimeImmutable();
        $diffSegundos = $prazo->getTimestamp() - $agora->getTimestamp();

        if ($diffSegundos <= 0) {
            return "VENCIDO h\u{e1} " . $this->formatarMinutos((int) (abs($diffSegundos) / 60));
        }

        return $this->formatarMinutos((int) ($diffSegundos / 60));
    }

    private function formatarMinutos(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $min = $minutos % 60;

        if ($horas === 0) {
            return "{$min}min";
        }

        return "{$horas}h{$min}min";
    }
}
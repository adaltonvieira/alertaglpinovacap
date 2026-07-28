<?php

namespace App\Services;

use App\Models\Chamado;

/**
 * Formata as mensagens enviadas ao Telegram em HTML, seguindo o layout
 * de referÃªncia do briefing, mas com prazos e criticidades derivados
 * do TR (via SlaEngine), nunca de valores fixos no template.
 */
class MessageFormatter
{
    public function __construct(private SlaEngine $sla)
    {
    }

    public function novoChamado(Chamado $c): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);

        return
            "ðŸš¨ <b>NOVO CHAMADO</b>\n\n" .
            "Chamado: <b>#{$c->numero}</b>\n\n" .
            "TÃ­tulo:\n{$c->titulo}\n\n" .
            "Categoria:\n{$c->categoria}\n\n" .
            "Solicitante:\n{$c->solicitanteNome}\n\n" .
            "Local:\n{$c->localidadeNome}\n\n" .
            "Unidade:\n{$c->unidade}\n\n" .
            "Data e hora:\n" . $c->abertoEm->format('d/m/Y H:i') . "\n\n" .
            "Prioridade:\n{$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "Impacto:\n{$this->sla->emojiImpacto($c->impacto)} " . ucfirst(strtolower($c->impacto)) . "\n\n" .
            "UrgÃªncia:\n{$this->sla->emojiUrgencia($c->urgencia)} " . ucfirst(strtolower($c->urgencia)) . "\n\n" .
            "SLA (NMS conforme Termo de ReferÃªncia):\n" . $this->descricaoPrazoResolucao($c) . "\n\n" .
            "Tempo restante:\n{$tempoRestante}\n\n" .
            "Clique para abrir:\n{$c->linkGlpi}";
    }

    public function chamadoAtribuido(Chamado $c, string $nomeTecnico): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);

        return
            "ðŸ“Œ <b>CHAMADO ATRIBUÃDO A VOCÃŠ</b>\n\n" .
            "TÃ©cnico: <b>{$nomeTecnico}</b>\n" .
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
            "ðŸ”„ <b>CHAMADO REATRIBUÃDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "TÃ©cnico anterior: {$tecnicoAnterior}\n" .
            "Novo tÃ©cnico: {$tecnicoNovo}\n" .
            "Data: " . (new \DateTimeImmutable())->format('d/m/Y H:i') . "\n";

        if ($motivo) {
            $msg .= "Motivo: {$motivo}\n";
        }

        return $msg . "\nLink: {$c->linkGlpi}";
    }

    public function alteracaoPrioridade(Chamado $c, array $antes): string
    {
        return
            "âš ï¸ <b>ALTERAÃ‡ÃƒO DE PRIORIDADE</b>\n\n" .
            "Chamado: #{$c->numero}\n\n" .
            "Antes:\n" .
            "  UrgÃªncia: {$antes['urgencia']}\n" .
            "  Impacto: {$antes['impacto']}\n" .
            "  Prioridade: {$antes['criticidade']}\n\n" .
            "Agora:\n" .
            "  UrgÃªncia: {$this->sla->emojiUrgencia($c->urgencia)} {$c->urgencia}\n" .
            "  Impacto: {$this->sla->emojiImpacto($c->impacto)} {$c->impacto}\n" .
            "  Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$c->criticidade}\n\n" .
            "Novo SLA: " . $this->descricaoPrazoResolucao($c) . "\n" .
            "Link: {$c->linkGlpi}";
    }

    public function alertaVencimento(Chamado $c, int $percentualConsumido): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);
        $emoji = $percentualConsumido >= 90 ? 'ðŸ”´' : ($percentualConsumido >= 75 ? 'ðŸŸ ' : 'ðŸŸ¡');

        return
            "{$emoji} <b>ATENÃ‡ÃƒO â€” SLA PRÃ“XIMO DO VENCIMENTO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "JÃ¡ consumido: {$percentualConsumido}% do prazo\n" .
            "Tempo restante: {$tempoRestante}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoVencido(Chamado $c): string
    {
        return
            "ðŸ”´ðŸ”¥ <b>CHAMADO COM SLA VENCIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Prazo previsto: {$c->prazoResolucao->format('d/m/Y H:i')}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "âš ï¸ Este chamado estÃ¡ fora do NMS definido no Termo de ReferÃªncia.\n" .
            "Link: {$c->linkGlpi}";
    }

    public function lembreteVencido(Chamado $c, int $minutosVencido): string
    {
        return
            "ðŸ”´ <b>LEMBRETE â€” CHAMADO AINDA VENCIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Vencido hÃ¡: " . $this->formatarMinutos($minutosVencido) . "\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoResolvido(Chamado $c, string $resolvidoPor, int $tempoGastoMinutos): string
    {
        $prazoMin = $this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade);
        $situacao = $tempoGastoMinutos <= $prazoMin ? 'âœ… Dentro do SLA' : 'âš ï¸ Fora do SLA';

        return
            "âœ… <b>CHAMADO RESOLVIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Resolvido por: {$resolvidoPor}\n" .
            "Tempo gasto: " . $this->formatarMinutos($tempoGastoMinutos) . "\n" .
            "SLA previsto: " . $this->formatarMinutos($prazoMin) . "\n" .
            "SituaÃ§Ã£o: {$situacao}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoFechado(Chamado $c): string
    {
        return "ðŸ”’ <b>CHAMADO ENCERRADO</b>\n\nChamado: #{$c->numero}\nLink: {$c->linkGlpi}";
    }

    private function descricaoPrazoResolucao(Chamado $c): string
    {
        $minutos = $this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade);
        return $this->formatarMinutos($minutos) .
            " (NMS " . ($c->tipo === 'INCIDENTE' ? 'Incidente' : 'RequisiÃ§Ã£o') .
            " / TR ANEXO IV)";
    }

    private function formatarTempoRestante(\DateTimeImmutable $prazo): string
    {
        $agora = new \DateTimeImmutable();
        $diffSegundos = $prazo->getTimestamp() - $agora->getTimestamp();

        if ($diffSegundos <= 0) {
            return 'VENCIDO hÃ¡ ' . $this->formatarMinutos((int) (abs($diffSegundos) / 60));
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

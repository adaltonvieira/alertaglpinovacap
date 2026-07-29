<?php

namespace App\Services;

use App\Models\Chamado;

/**
 * Formata as mensagens enviadas ao Telegram em HTML.
 *
 * Templates definidos em conjunto com a NOVACAP (jul/2026):
 *  - novoChamado(): mensagem enviada ao GRUPO "Notificações Glpi Novacap".
 *    Só é disparada para chamados com prioridade Média/Alta/Crítica
 *    (filtro aplicado em GlpiSyncWorker::notificarNovoChamado).
 *  - chamadoAtribuido(): mensagem enviada ao PRIVADO do técnico quando o
 *    chamado é atribuído a ele.
 *
 * Prazos e criticidades sempre derivados do TR (via SlaEngine), nunca de
 * valores fixos no template.
 */
class MessageFormatter
{
    public function __construct(private SlaEngine $sla)
    {
    }

    /**
     * Mensagem para o GRUPO de notificações.
     * Campos: Número, Título, Categoria, Prioridade, SLA, Vence em, Grupo Responsável.
     */
    public function novoChamado(Chamado $c): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);
        $slaTotal = $this->formatarMinutos($this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade));

        return
            "🔔 <b>NOVO CHAMADO</b>\n\n" .
            "📎 <b>#{$c->numero}</b>\n" .
            "📝 {$c->titulo}\n\n" .
            "📁 <b>Categoria</b>\n{$c->categoria}\n\n" .
            "{$this->sla->emojiCriticidade($c->criticidade)} <b>Prioridade:</b> {$this->sla->labelCriticidade($c->criticidade)}\n" .
            "⏳ <b>SLA:</b> {$slaTotal}\n" .
            "⏳ <b>Vence em:</b> {$tempoRestante}\n\n" .
            "👥 <b>Grupo Responsável</b>\n{$this->equipeLabel($c->equipeAtual)}";
    }

    /**
     * Mensagem enviada ao técnico no privado quando o chamado é atribuído.
     * Campos: Técnico, Número, Título, Requerente, Localização, Categoria,
     * Prioridade, SLA, Restante.
     */
    public function chamadoAtribuido(Chamado $c, string $nomeTecnico): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);
        $slaTotal = $this->formatarMinutos($this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade));
        $localizacao = $c->unidade ?: '-';

        return
            "📌 <b>CHAMADO ATRIBUÍDO A VOCÊ</b>\n\n" .
            "👤 <b>Técnico:</b> {$nomeTecnico}\n\n" .
            "📎 <b>#{$c->numero}</b>\n" .
            "📝 {$c->titulo}\n\n" .
            "👤 <b>Requerente:</b> {$c->solicitanteNome}\n" .
            "🏢 <b>Localização:</b> {$localizacao}\n\n" .
            "📁 <b>Categoria</b>\n{$c->categoria}\n\n" .
            "{$this->sla->emojiCriticidade($c->criticidade)} <b>Prioridade:</b> {$this->sla->labelCriticidade($c->criticidade)}\n" .
            "⏳ <b>SLA:</b> {$slaTotal}\n" .
            "⏳ <b>Restante:</b> {$tempoRestante}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function reatribuicao(Chamado $c, string $tecnicoAnterior, string $tecnicoNovo, ?string $motivo): string
    {
        $msg =
            "🔄 <b>CHAMADO REATRIBUÍDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Técnico anterior: {$tecnicoAnterior}\n" .
            "Novo técnico: {$tecnicoNovo}\n" .
            "Data: " . (new \DateTimeImmutable())->format('d/m/Y H:i') . "\n";

        if ($motivo) {
            $msg .= "Motivo: {$motivo}\n";
        }

        return $msg . "\nLink: {$c->linkGlpi}";
    }

    public function alteracaoPrioridade(Chamado $c, array $antes): string
    {
        return
            "⚠️ <b>ALTERAÇÃO DE PRIORIDADE</b>\n\n" .
            "Chamado: #{$c->numero}\n\n" .
            "Antes:\n" .
            "  Urgência: {$antes['urgencia']}\n" .
            "  Impacto: {$antes['impacto']}\n" .
            "  Prioridade: {$antes['criticidade']}\n\n" .
            "Agora:\n" .
            "  Urgência: {$this->sla->emojiUrgencia($c->urgencia)} {$c->urgencia}\n" .
            "  Impacto: {$this->sla->emojiImpacto($c->impacto)} {$c->impacto}\n" .
            "  Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$c->criticidade}\n\n" .
            "Novo SLA: " . $this->descricaoPrazoResolucao($c) . "\n" .
            "Link: {$c->linkGlpi}";
    }

    public function alertaVencimento(Chamado $c, int $percentualConsumido): string
    {
        $tempoRestante = $this->formatarTempoRestante($c->prazoResolucao);
        $emoji = $percentualConsumido >= 90 ? '🔴' : ($percentualConsumido >= 75 ? '🟠' : '🟡');

        return
            "{$emoji} <b>ATENÇÃO — SLA PRÓXIMO DO VENCIMENTO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Já consumido: {$percentualConsumido}% do prazo\n" .
            "Tempo restante: {$tempoRestante}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoVencido(Chamado $c): string
    {
        return
            "🔴🔥 <b>CHAMADO COM SLA VENCIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Prazo previsto: {$c->prazoResolucao->format('d/m/Y H:i')}\n" .
            "Prioridade: {$this->sla->emojiCriticidade($c->criticidade)} {$this->sla->labelCriticidade($c->criticidade)}\n\n" .
            "⚠️ Este chamado está fora do NMS definido no Termo de Referência.\n" .
            "Link: {$c->linkGlpi}";
    }

    public function lembreteVencido(Chamado $c, int $minutosVencido): string
    {
        return
            "🔴 <b>LEMBRETE — CHAMADO AINDA VENCIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Vencido há: " . $this->formatarMinutos($minutosVencido) . "\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoResolvido(Chamado $c, string $resolvidoPor, int $tempoGastoMinutos): string
    {
        $prazoMin = $this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade);
        $situacao = $tempoGastoMinutos <= $prazoMin ? '✅ Dentro do SLA' : '⚠️ Fora do SLA';

        return
            "✅ <b>CHAMADO RESOLVIDO</b>\n\n" .
            "Chamado: #{$c->numero}\n" .
            "Resolvido por: {$resolvidoPor}\n" .
            "Tempo gasto: " . $this->formatarMinutos($tempoGastoMinutos) . "\n" .
            "SLA previsto: " . $this->formatarMinutos($prazoMin) . "\n" .
            "Situação: {$situacao}\n\n" .
            "Link: {$c->linkGlpi}";
    }

    public function chamadoFechado(Chamado $c): string
    {
        return "🔒 <b>CHAMADO ENCERRADO</b>\n\nChamado: #{$c->numero}\nLink: {$c->linkGlpi}";
    }

    private function descricaoPrazoResolucao(Chamado $c): string
    {
        $minutos = $this->sla->prazoResolucaoMinutos($c->tipo, $c->criticidade);
        return $this->formatarMinutos($minutos) .
            " (NMS " . ($c->tipo === 'INCIDENTE' ? 'Incidente' : 'Requisição') .
            " / TR ANEXO IV)";
    }

    /**
     * Rótulo amigável do grupo/equipe responsável exibido nas mensagens.
     * Ex.: "N1" -> "N1 Presencial". Ajustar aqui se os nomes reais das
     * equipes na NOVACAP forem diferentes.
     */
    private function equipeLabel(string $equipe): string
    {
        return match ($equipe) {
            'N1'  => 'N1 Presencial',
            'N2'  => 'N2 Remoto',
            'N3'  => 'N3 Infraestrutura',
            'NOC' => 'NOC 24x7',
            default => $equipe,
        };
    }

    private function formatarTempoRestante(\DateTimeImmutable $prazo): string
    {
        $agora = new \DateTimeImmutable();
        $diffSegundos = $prazo->getTimestamp() - $agora->getTimestamp();

        if ($diffSegundos <= 0) {
            return 'VENCIDO há ' . $this->formatarMinutos((int) (abs($diffSegundos) / 60));
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

        if ($min === 0) {
            return "{$horas}h";
        }

        return "{$horas}h{$min}min";
    }
}

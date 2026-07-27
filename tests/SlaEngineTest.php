<?php

use PHPUnit\Framework\TestCase;
use App\Services\SlaEngine;

/**
 * Testes que travam os valores do motor de SLA aos números literais do
 * Termo de Referência (ANEXO IV, Tabelas XVI, XIX e XX). Qualquer alteração
 * futura em config/sla.php que quebre estes testes deve ser tratada como
 * mudança de escopo contratual e revisada com o NTI/NOVACAP antes do merge.
 */
class SlaEngineTest extends TestCase
{
    private SlaEngine $sla;

    protected function setUp(): void
    {
        $this->sla = new SlaEngine(require dirname(__DIR__) . '/config/sla.php');
    }

    public function testPrazoInicioAtendimentoTabelaXVI(): void
    {
        $this->assertSame(20, $this->sla->prazoInicioAtendimentoMinutos('CRITICA'));
        $this->assertSame(40, $this->sla->prazoInicioAtendimentoMinutos('ALTA'));
        $this->assertSame(60, $this->sla->prazoInicioAtendimentoMinutos('MEDIA'));
        $this->assertSame(120, $this->sla->prazoInicioAtendimentoMinutos('BAIXA'));
    }

    public function testPrazoResolucaoIncidenteTabelaXIX(): void
    {
        $this->assertSame(120, $this->sla->prazoResolucaoMinutos('INCIDENTE', 'CRITICA'));  // 2h
        $this->assertSame(240, $this->sla->prazoResolucaoMinutos('INCIDENTE', 'ALTA'));     // 4h
        $this->assertSame(360, $this->sla->prazoResolucaoMinutos('INCIDENTE', 'MEDIA'));    // 6h
        $this->assertSame(480, $this->sla->prazoResolucaoMinutos('INCIDENTE', 'BAIXA'));    // 8h
    }

    public function testPrazoResolucaoRequisicaoTabelaXX(): void
    {
        $this->assertSame(180, $this->sla->prazoResolucaoMinutos('REQUISICAO', 'CRITICA'));  // 3h
        $this->assertSame(480, $this->sla->prazoResolucaoMinutos('REQUISICAO', 'ALTA'));     // 8h
        $this->assertSame(600, $this->sla->prazoResolucaoMinutos('REQUISICAO', 'MEDIA'));    // 10h
        $this->assertSame(1440, $this->sla->prazoResolucaoMinutos('REQUISICAO', 'BAIXA'));   // 24h
    }

    public function testCalculoCriticidadeImpactoAltissimoUrgenciaCritica(): void
    {
        $this->assertSame('CRITICA', $this->sla->calcularCriticidade('ALTISSIMO', 'CRITICA'));
    }

    public function testCalculoCriticidadeImpactoBaixoUrgenciaBaixa(): void
    {
        $this->assertSame('BAIXA', $this->sla->calcularCriticidade('BAIXO', 'BAIXA'));
    }

    public function testPrazoLimiteForaDoHorarioComercialEmpurraParaProximoExpediente(): void
    {
        // Chamado aberto sexta-feira às 18:50 (N1/N2, expediente até 19:00),
        // criticidade BAIXA (prazo de início = 120min) deve ultrapassar o
        // expediente e continuar na segunda-feira seguinte.
        $abertura = new DateTimeImmutable('2026-01-30 18:50:00'); // sexta-feira
        $limite = $this->sla->calcularPrazoLimite($abertura, 120, 'N1');

        $this->assertSame('mon', strtolower($limite->format('D')));
    }

    public function testJanelaNocEDurante24x7NaoRespeitaLimiteDeExpediente(): void
    {
        $abertura = new DateTimeImmutable('2026-01-31 23:30:00'); // sábado à noite
        $limite = $this->sla->calcularPrazoLimite($abertura, 60, 'NOC');

        $this->assertSame('2026-02-01 00:30:00', $limite->format('Y-m-d H:i:s'));
    }
}

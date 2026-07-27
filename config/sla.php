<?php
/**
 * =============================================================================
 * CONFIGURAÇÃO DE NÍVEIS MÍNIMOS DE SERVIÇO (NMS)
 * Fonte: Termo de Referência NOVACAP/PRES/NTI (SEI 00112-00010789/2024-52)
 *        ANEXO III - Catálogo de Serviços
 *        ANEXO IV  - Especificações Técnicas e Requisitos Profissionais
 * =============================================================================
 *
 * Este arquivo é a ÚNICA fonte de verdade para prazos, criticidades e janelas
 * de atendimento usadas pelo motor de SLA e pelo bot do Telegram. Qualquer
 * alteração aqui deve ser justificada por um item específico do TR (citado
 * em cada bloco como referência "TR §").
 */

return [

    // -------------------------------------------------------------------
    // TR § 6.9 / Tabela III, IV e VI (ANEXO IV) — Escalas de classificação
    // -------------------------------------------------------------------
    'impacto' => [
        'ALTISSIMO' => ['label' => 'Altíssimo', 'emoji' => '🚨', 'peso' => 5],
        'ALTO'      => ['label' => 'Alto',      'emoji' => '🏢', 'peso' => 4],
        'ELEVADO'   => ['label' => 'Elevado',   'emoji' => '👥', 'peso' => 3],
        'MEDIO'     => ['label' => 'Médio',     'emoji' => '👤', 'peso' => 2],
        'BAIXO'     => ['label' => 'Baixo',     'emoji' => '🟢', 'peso' => 1],
    ],

    'urgencia' => [
        'CRITICA' => ['label' => 'Crítica', 'emoji' => '🔴', 'peso' => 4],
        'ALTA'    => ['label' => 'Alta',    'emoji' => '🟠', 'peso' => 3],
        'MEDIA'   => ['label' => 'Média',   'emoji' => '🟡', 'peso' => 2],
        'BAIXA'   => ['label' => 'Baixa',   'emoji' => '🟢', 'peso' => 1],
    ],

    // TR ANEXO IV, item 3.10 / Tabela XVI — Criticidade do chamado
    // (usada para tempo de INÍCIO de atendimento, não de resolução)
    'criticidade_atendimento' => [
        'CRITICA' => [
            'label'                  => 'Crítica',
            'emoji'                  => '🔴',
            'descricao'              => 'Falha que impede a continuidade da prestação de serviços ao público interno, ou incidente que impossibilite o trabalho de vários usuários.',
            'prazo_inicio_minutos'   => 20,
        ],
        'ALTA' => [
            'label'                  => 'Alta',
            'emoji'                  => '🟠',
            'descricao'              => 'Um ou mais componentes da infraestrutura de serviços de TIC apresentam funcionalidades indisponíveis ou afetadas.',
            'prazo_inicio_minutos'   => 40,
        ],
        'MEDIA' => [
            'label'                  => 'Média',
            'emoji'                  => '🟡',
            'descricao'              => 'Incidente que não configura falha em serviço de TIC, sem impedir que outras atividades continuem sendo realizadas.',
            'prazo_inicio_minutos'   => 60,
        ],
        'BAIXA' => [
            'label'                  => 'Baixa',
            'emoji'                  => '🟢',
            'descricao'              => 'Solicitação de melhorias ou de hardware para substituição, sem impedir a execução do trabalho do usuário.',
            'prazo_inicio_minutos'   => 120,
        ],
    ],

    // TR ANEXO IV, item 3.24.6 / Tabela XIX — Prazo MÁXIMO de RESOLUÇÃO de INCIDENTES
    'incidente_prazo_maximo' => [
        'CRITICA' => 2 * 60,   // 2h00
        'ALTA'    => 4 * 60,   // 4h00
        'MEDIA'   => 6 * 60,   // 6h00
        'BAIXA'   => 8 * 60,   // 8h00
    ],

    // TR ANEXO IV, item 3.26.8 / Tabela XX — REQUISIÇÕES DE SERVIÇO
    // tempo médio esperado e prazo máximo tolerado
    'requisicao_sla' => [
        'CRITICA' => ['tempo_medio_min' => 2 * 60,  'prazo_maximo_min' => 3 * 60],
        'ALTA'    => ['tempo_medio_min' => 5 * 60,  'prazo_maximo_min' => 8 * 60],
        'MEDIA'   => ['tempo_medio_min' => 8 * 60,  'prazo_maximo_min' => 10 * 60],
        'BAIXA'   => ['tempo_medio_min' => 20 * 60, 'prazo_maximo_min' => 24 * 60],
    ],

    // TR ANEXO IV, item 3.23.1 / Tabela XVIII — categorização geral de demandas
    // (usada quando o chamado não é diferenciado entre incidente/requisição)
    'demanda_prazo_maximo_horas' => [
        'CRITICA' => 3,
        'ALTA'    => 8,
        'MEDIA'   => 10,
        'BAIXA'   => 24,
    ],

    // -------------------------------------------------------------------
    // TR § 12.2 / 12.3 (Tabela V) e ANEXO IV item 1.3/1.4 — Janelas de atendimento
    // -------------------------------------------------------------------
    // Usado para: (a) contar prazos apenas em "horas úteis" quando aplicável
    //             (b) decidir se dispara alerta em horário comercial ou plantão
    'janelas_atendimento' => [
        // Nível 1 e 2 (Service Desk híbrido/presencial) — TR ANEXO IV item 1.3
        'N1_N2' => [
            'dias_uteis' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'inicio'     => '07:00',
            'fim'        => '19:00',
        ],
        // Nível 3 (Operação de Infraestrutura) — TR ANEXO IV item 1.3
        'N3' => [
            'dias_uteis' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'inicio'     => '08:00',
            'fim'        => '18:00',
        ],
        // Monitoração NOC e tratamento de incidentes — TR § 10.3 / Tabela V: 24x7x365
        'NOC' => [
            'dias_uteis' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'inicio'     => '00:00',
            'fim'        => '23:59',
            '24x7'       => true,
        ],
    ],

    // Regra de contagem de prazo: TR ANEXO IV item 3.11 diz que os prazos de
    // atendimento (Tabela XVI) são contados em HORAS ÚTEIS, ou seja, apenas
    // dentro do horário de atendimento previsto.
    'contar_prazo_apenas_horario_util' => true,

    // -------------------------------------------------------------------
    // Notificações de aproximação de vencimento (não definidas literalmente
    // pelo TR, mas necessárias operacionalmente para o cumprimento das metas
    // do TR ANEXO IV item 3.17/3.19 — Ajustes de fatura por descumprimento de
    // NMS). Escalonadas proporcionalmente ao prazo de cada criticidade.
    // -------------------------------------------------------------------
    'alertas_vencimento' => [
        // percentuais do prazo total já consumido que disparam alerta
        'percentuais' => [50, 75, 90, 95],
        // Reforço adicional nos últimos minutos absolutos (todas criticidades)
        'minutos_finais' => [15, 5],
    ],

    // TR ANEXO IV, item 3.24.11 e Tabela XVII — cadência de lembrete após
    // vencido, alinhada aos redutores por descumprimento de NMS.
    'lembrete_pos_vencimento_minutos' => [15, 30, 60], // depois: a cada 60min até encerramento

    // -------------------------------------------------------------------
    // TR § 6.9.3 / Tabela XVII — Descontos por descumprimento (referência
    // apenas informativa, exibida em relatório gerencial; não altera prazos)
    // -------------------------------------------------------------------
    'redutor_financeiro_percentual_atendimento' => [
        94 => 0.0, 93 => 0.5, 92 => 1.0, 91 => 1.5, 90 => 2.0,
        89 => 2.5, 88 => 3.0, 87 => 3.5, 86 => 4.0, 85 => 4.5, 84 => 5.0,
    ],

    // -------------------------------------------------------------------
    // Escalonamento hierárquico (funcionalidade adicional, justificada
    // tecnicamente na seção "Melhorias" — apoia o cumprimento do TR item
    // 3.17 ao evitar estouro de NMS por ausência de atribuição)
    // -------------------------------------------------------------------
    'escalonamento' => [
        // Se chamado CRÍTICO ficar sem técnico atribuído por X minutos,
        // parte do prazo de início (20min) => escalar em 10min (50%)
        'sem_atribuicao_minutos' => [
            'CRITICA' => 10,
            'ALTA'    => 20,
            'MEDIA'   => 30,
            'BAIXA'   => 60,
        ],
    ],
];

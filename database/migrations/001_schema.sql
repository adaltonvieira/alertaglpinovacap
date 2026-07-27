-- =============================================================================
-- Schema do banco de dados — GLPI <-> Telegram Integration
-- Campos alinhados ao TR NOVACAP (localidades ANEXO IV Tabela VI, torres de
-- serviço ANEXO IV item 2.2, criticidade ANEXO IV Tabela XVI).
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE TABLE IF NOT EXISTS tecnicos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(150) NOT NULL,
    telegram_chat_id    BIGINT NOT NULL,
    telegram_username   VARCHAR(100) NULL,
    glpi_user_id        INT UNSIGNED NOT NULL,
    -- Torre de serviço conforme TR ANEXO IV Tabela III (ex: N1, N2, N3, NOC)
    equipe              ENUM('N1','N2','N3','NOC','GESTAO') NOT NULL,
    grupo_glpi_id       INT UNSIGNED NULL,
    horario_inicio      TIME NOT NULL DEFAULT '07:00:00',
    horario_fim         TIME NOT NULL DEFAULT '19:00:00',
    plantao_24x7        TINYINT(1) NOT NULL DEFAULT 0,
    ativo               TINYINT(1) NOT NULL DEFAULT 1,
    supervisor_id       INT UNSIGNED NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supervisor_id) REFERENCES tecnicos(id) ON DELETE SET NULL,
    UNIQUE KEY uq_glpi_user (glpi_user_id),
    UNIQUE KEY uq_telegram_chat (telegram_chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grupos_telegram (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome                VARCHAR(100) NOT NULL,        -- ex.: "Novo Chamado", "N1 - Suporte", "NOC 24x7"
    chat_id             BIGINT NOT NULL,
    tipo                ENUM('novo_chamado','equipe','gestores','plantao') NOT NULL,
    equipe_vinculada    ENUM('N1','N2','N3','NOC','GESTAO') NULL,
    ativo               TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_chat_tipo (chat_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Localidades da NOVACAP — TR ANEXO IV Tabela VI
CREATE TABLE IF NOT EXISTS localidades (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(150) NOT NULL,   -- ex.: "NOVACAP SEDE", "DRNO", "DRSU"...
    endereco    VARCHAR(255) NULL,
    glpi_entity_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chamados (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    glpi_ticket_id          INT UNSIGNED NOT NULL,
    numero                  VARCHAR(30) NOT NULL,
    titulo                  VARCHAR(255) NOT NULL,
    -- TR ANEXO III item 1.8: chamados classificados como Incidente ou Requisição
    tipo                    ENUM('INCIDENTE','REQUISICAO') NOT NULL,
    categoria               VARCHAR(150) NULL,
    solicitante_nome        VARCHAR(150) NULL,
    solicitante_glpi_id     INT UNSIGNED NULL,
    localidade_id           INT UNSIGNED NULL,
    unidade                 VARCHAR(150) NULL,

    -- Classificações TR ANEXO IV Tabela III/IV/XVI
    impacto                 ENUM('ALTISSIMO','ALTO','ELEVADO','MEDIO','BAIXO') NOT NULL DEFAULT 'MEDIO',
    urgencia                ENUM('CRITICA','ALTA','MEDIA','BAIXA') NOT NULL DEFAULT 'MEDIA',
    criticidade             ENUM('CRITICA','ALTA','MEDIA','BAIXA') NOT NULL DEFAULT 'MEDIA',

    equipe_atual            ENUM('N1','N2','N3','NOC') NOT NULL DEFAULT 'N1',
    tecnico_atual_id        INT UNSIGNED NULL,

    status_glpi             VARCHAR(40) NOT NULL,  -- novo, atribuido, planejado, pendente, resolvido, fechado (mapeado do GLPI)

    -- Prazos calculados no momento da abertura/reclassificação (config/sla.php)
    prazo_inicio_atendimento DATETIME NULL,
    prazo_resolucao          DATETIME NULL,

    aberto_em               DATETIME NOT NULL,
    atribuido_em            DATETIME NULL,
    resolvido_em            DATETIME NULL,
    fechado_em              DATETIME NULL,

    sla_violado             TINYINT(1) NOT NULL DEFAULT 0,
    sla_violado_em          DATETIME NULL,

    criado_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (localidade_id) REFERENCES localidades(id) ON DELETE SET NULL,
    FOREIGN KEY (tecnico_atual_id) REFERENCES tecnicos(id) ON DELETE SET NULL,
    UNIQUE KEY uq_glpi_ticket (glpi_ticket_id),
    INDEX idx_status (status_glpi),
    INDEX idx_criticidade (criticidade),
    INDEX idx_prazo_resolucao (prazo_resolucao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Histórico de reatribuições — TR "Reatribuição" (funcionalidade 3)
CREATE TABLE IF NOT EXISTS chamado_reatribuicoes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id      INT UNSIGNED NOT NULL,
    tecnico_anterior_id INT UNSIGNED NULL,
    tecnico_novo_id     INT UNSIGNED NULL,
    motivo          VARCHAR(255) NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log de notificações enviadas — usado para deduplicação/cooldown (anti-spam)
CREATE TABLE IF NOT EXISTS notificacoes_enviadas (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id      INT UNSIGNED NOT NULL,
    tipo_evento     VARCHAR(60) NOT NULL,   -- novo_chamado, atribuicao, reatribuicao, alteracao_prioridade,
                                             -- vencimento_50, vencimento_75, vencimento_90, vencimento_95,
                                             -- vencido, lembrete_vencido, resolvido, fechado
    destino_chat_id BIGINT NOT NULL,
    telegram_message_id BIGINT NULL,
    payload_hash    CHAR(40) NOT NULL,      -- sha1 do conteúdo, para dedup
    enviado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    INDEX idx_dedup (chamado_id, tipo_evento, payload_hash),
    INDEX idx_enviado_em (enviado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fila assíncrona de mensagens (também espelhada em Redis; tabela serve
-- como fallback/persistência e auditoria)
CREATE TABLE IF NOT EXISTS fila_mensagens (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id      INT UNSIGNED NULL,
    destino_chat_id BIGINT NOT NULL,
    tipo_evento     VARCHAR(60) NOT NULL,
    payload_json    JSON NOT NULL,
    status          ENUM('pendente','processando','enviado','falhou') NOT NULL DEFAULT 'pendente',
    tentativas      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_tentativas  TINYINT UNSIGNED NOT NULL DEFAULT 5,
    proxima_tentativa_em DATETIME NULL,
    erro_ultimo     TEXT NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_proxima (status, proxima_tentativa_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auditoria geral (logs seguros — requisito de segurança do prompt)
CREATE TABLE IF NOT EXISTS auditoria_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ator            VARCHAR(100) NOT NULL,  -- 'system', 'webhook', 'telegram:<user_id>', 'cron'
    acao            VARCHAR(100) NOT NULL,
    entidade        VARCHAR(60) NULL,
    entidade_id     VARCHAR(60) NULL,
    detalhes_json   JSON NULL,
    ip_origem       VARCHAR(45) NULL,
    criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_acao (acao),
    INDEX idx_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Checkpoint de sincronização (polling otimizado — evita reprocessar tudo)
CREATE TABLE IF NOT EXISTS sync_checkpoint (
    chave       VARCHAR(60) PRIMARY KEY,
    valor       VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Confirmação de leitura pelo técnico (funcionalidade extra sugerida)
CREATE TABLE IF NOT EXISTS confirmacoes_leitura (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id      INT UNSIGNED NOT NULL,
    tecnico_id      INT UNSIGNED NOT NULL,
    confirmado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    FOREIGN KEY (tecnico_id) REFERENCES tecnicos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_chamado_tecnico (chamado_id, tecnico_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

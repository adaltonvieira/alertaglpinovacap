CREATE TABLE IF NOT EXISTS rejeicoes_pendentes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id          INT UNSIGNED NOT NULL,
    telegram_user_id    BIGINT NOT NULL,
    prompt_chat_id      BIGINT NOT NULL,
    prompt_message_id   BIGINT NOT NULL,
    criado_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    INDEX idx_prompt (prompt_chat_id, prompt_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

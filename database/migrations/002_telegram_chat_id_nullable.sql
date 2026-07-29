-- Permite cadastrar tecnicos ANTES de saber o telegram_chat_id deles
-- (fluxo: cadastra nome/GLPI ID/equipe primeiro, pessoa manda /start
-- depois, e so entao preenchemos o telegram_chat_id via UPDATE).
--
-- MySQL permite multiplos valores NULL numa coluna UNIQUE sem conflito,
-- entao isso e seguro mesmo com varios tecnicos pendentes ao mesmo tempo.

ALTER TABLE tecnicos MODIFY telegram_chat_id BIGINT NULL;

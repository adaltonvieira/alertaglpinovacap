-- Cadastro em lote dos tecnicos informados pela NOVACAP (29/07/2026).
-- telegram_chat_id fica NULL ate cada pessoa mandar /start pro bot.
-- Depois, atualizar cada um com o comando UPDATE no final deste arquivo.

INSERT INTO tecnicos (nome, glpi_user_id, equipe, ativo) VALUES
('Daniel Areda de Abreu', 1784, 'N1', 1),
('Ana Clara Moreira Lima', 3671, 'N1', 1),
('Marlus Donizethe Borges Domiciano', 3644, 'N1', 1),
('Gabriel Gomes', 3218, 'N1', 1),
('Larissa Lima', 3439, 'N1', 1),
('Carlos Alberto da Silva Junior', 1256, 'N2', 1),
('Vitor Cunha', 3423, 'N2', 1),
('Alexandre Leucas Browne', 1649, 'N2', 1),
('Pedro Neto', 2923, 'N2', 1),
('Wellison Pinheiro', 3325, 'N2', 1),
('Victor Quintino', 1254, 'N2', 1),
('Milton Silva', 3582, 'N2', 1),
('Pedro Silva', 3039, 'N2', 1),
('Breno Ayres Torres de Lima', 3659, 'N3', 1),
('Cristiano Cardoso', 1249, 'N3', 1),
('Harrison de Castro Silva', 1253, 'N3', 1),
('Monitoramento Regent', 1141, 'N3', 1),
('Adriano Santos', 3609, 'N3', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), equipe = VALUES(equipe);

-- Adalton Silva (glpi_user_id 3584) ja foi cadastrado antes com o
-- telegram_chat_id dele - so garantimos que nome/equipe estao corretos,
-- sem mexer no chat_id que ja existe.
INSERT INTO tecnicos (nome, glpi_user_id, equipe, ativo, telegram_chat_id)
VALUES ('Adalton Silva', 3584, 'N1', 1, 1147736594)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), equipe = VALUES(equipe);

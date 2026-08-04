UPDATE tecnicos SET telegram_chat_id=5211290039, equipe='N1' WHERE glpi_user_id=1784;
UPDATE tecnicos SET telegram_chat_id=1116582772, equipe='N1' WHERE glpi_user_id=3439;
UPDATE tecnicos SET telegram_chat_id=7617812617, equipe='N2' WHERE glpi_user_id=3423;
UPDATE tecnicos SET telegram_chat_id=5581744284, equipe='N2' WHERE glpi_user_id=1649;
UPDATE tecnicos SET telegram_chat_id=5343934855, equipe='N2' WHERE glpi_user_id=3325;
UPDATE tecnicos SET telegram_chat_id=1710329361, equipe='N2' WHERE glpi_user_id=1254;
UPDATE tecnicos SET telegram_chat_id=6658493881, equipe='N2' WHERE glpi_user_id=3582;
UPDATE tecnicos SET telegram_chat_id=2065409364, equipe='N2' WHERE glpi_user_id=3039;
UPDATE tecnicos SET telegram_chat_id=5002378296, equipe='N3' WHERE glpi_user_id=3659;
UPDATE tecnicos SET telegram_chat_id=7488707615, equipe='N3' WHERE glpi_user_id=1249;
UPDATE tecnicos SET telegram_chat_id=1297263691, equipe='N3' WHERE glpi_user_id=1253;
UPDATE tecnicos SET telegram_chat_id=49989008, equipe='N3' WHERE glpi_user_id=3609;
UPDATE tecnicos SET telegram_chat_id=266401234, equipe='GESTAO' WHERE glpi_user_id=1251;
UPDATE tecnicos SET telegram_chat_id=1701012507, equipe='GESTAO' WHERE glpi_user_id=3670;
UPDATE tecnicos SET telegram_chat_id=8165636595, equipe='GESTAO' WHERE glpi_user_id=3644;

INSERT INTO tecnicos (nome, glpi_user_id, equipe, ativo, telegram_chat_id)
VALUES ('Leonardo Vitalino', 3667, 'N2', 1, 8925364500)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), equipe = VALUES(equipe), telegram_chat_id = VALUES(telegram_chat_id);

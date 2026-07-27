<?php
/**
 * Mapeamento entre IDs de grupo do GLPI (groups_id_assign) e as torres de
 * serviço definidas no TR ANEXO IV (item 2.2 / Tabela III):
 *   N1  -> Atendimento de 1º Nível (Service Desk remoto)
 *   N2  -> Atendimento de 2º Nível (Service Desk presencial)
 *   N3  -> Operação de Infraestrutura de TIC (3º Nível)
 *
 * IMPORTANTE: os IDs abaixo são placeholders. Devem ser substituídos pelos
 * IDs reais dos grupos cadastrados na instância GLPI da NOVACAP durante a
 * fase de implantação/parametrização (TR item 8.2 — prazo de 15 dias).
 */

return [
    'base_url'   => getenv('GLPI_BASE_URL'),
    'app_token'  => getenv('GLPI_APP_TOKEN'),
    'user_token' => getenv('GLPI_USER_TOKEN'),
    'timeout'    => 10,

    'grupos_glpi_para_equipe' => [
        // <id_grupo_glpi> => 'N1' | 'N2' | 'N3'
        16 => 'N1',
        1 => 'N2',
        9 => 'N3',
    ],
];

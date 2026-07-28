<?php
return [
    'base_url'   => getenv('GLPI_BASE_URL'),
    'app_token'  => getenv('GLPI_APP_TOKEN'),
    'user_token' => getenv('GLPI_USER_TOKEN'),
    'timeout'    => 10,

    'verify_ssl' => (getenv('GLPI_VERIFY_SSL') !== '0'),
    'ca_bundle'  => getenv('GLPI_CA_BUNDLE') ?: null,

    'grupos_glpi_para_equipe' => [
        1 => 'N1',
        2 => 'N2',
        3 => 'N3',
        4 => 'NOC',
    ],
];

<?php

namespace App\Support;

class GlpiUrl
{
    public static function ticketLink(int $ticketId): string
    {
        $apiUrl = getenv('GLPI_BASE_URL') ?: '';
        $webBase = preg_replace('#/apirest\.php/?$#', '', $apiUrl);

        return rtrim($webBase, '/') . '/front/ticket.form.php?id=' . $ticketId;
    }
}

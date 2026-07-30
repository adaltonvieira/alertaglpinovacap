#!/bin/bash
set -e

a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

apache2ctl -t

php /var/www/html/scripts/migrate.php || echo "AVISO: migracao falhou ou banco ainda indisponivel - verifique os logs acima."

service cron start

mkdir -p /var/www/html/logs
nohup php /var/www/html/scripts/worker_dispatch_notifications.php >> /var/www/html/logs/dispatch.log 2>&1 &
nohup php /var/www/html/scripts/worker_retry_fila.php >> /var/www/html/logs/retry.log 2>&1 &

exec "$@"


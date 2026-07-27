FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        git unzip cron libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

COPY . .

COPY crontab /etc/cron.d/glpi-telegram-cron
RUN chmod 0644 /etc/cron.d/glpi-telegram-cron && crontab /etc/cron.d/glpi-telegram-cron

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]

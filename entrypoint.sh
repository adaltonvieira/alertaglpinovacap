#!/bin/bash
set -e

# -----------------------------------------------------------------------
# Fix conhecido: "AH00534: apache2: Configuration error: More than one
# MPM loaded" na imagem php:8.2-apache rodando no Railway.
#
# Causa relatada pela comunidade Railway: o ambiente de deploy do Railway
# reseta/reconstroi o estado de mods-enabled do Apache entre o build e o
# start do container, entao corrigir apenas no Dockerfile (build time) nao
# e suficiente - a correcao precisa ser reaplicada a cada start do
# container.
# -----------------------------------------------------------------------
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

apache2ctl -t

service cron start

exec "$@"
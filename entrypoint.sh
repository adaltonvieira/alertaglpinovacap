#!/bin/bash
set -e

service cron start

exec "$@"

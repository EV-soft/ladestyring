#!/bin/sh
# docker-entrypoint.sh v:1.0.0 d:2026-10-23 i:evs
# Kører planlægningen hvert kvarter (lige efter :00 :15 :30 :45) og serverer siden på port $EP_PORT.
cd /app
(
  while true; do
    php ladestyring-cron.php > /dev/null 2>&1
    sleep $(( 900 - $(date +%s) % 900 + 5 ))
  done
) &
exec php -S 0.0.0.0:${EP_PORT:-8890} -t /app /app/router.php

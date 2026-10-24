# Dockerfile v:1.1.0 d:2026-10-24 i:evs
# Ladestyring i en container (fx Synology Docker): PHP 8.3 + Python 3 med tinytuya. Koden og data/ ligger i mappen udenfor (volumen /app).
FROM php:8.3-cli-alpine
RUN apk add --no-cache python3 py3-pip py3-cryptography py3-requests py3-colorama tzdata \
 && pip install --no-cache-dir --break-system-packages tinytuya==1.20.0 \
 && ln -sf /usr/bin/python3 /usr/bin/python
ENV TZ=Europe/Copenhagen  EP_PORT=8890  PHP_CLI_SERVER_WORKERS=3
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh
WORKDIR /app
CMD ["/docker-entrypoint.sh"]

# Samostatný obraz pre hosting (Fly, Render, VPS): PHP + SQLite z data/parcely.sqlite.
FROM php:8.3-cli

WORKDIR /app
COPY . .

# Vstavaný server s viacerými workermi; dlaždice sa cachujú do public/tiles.
ENV PHP_CLI_SERVER_WORKERS=4
RUN mkdir -p public/tiles && chmod -R a+rwX public/tiles

EXPOSE 8080
CMD ["sh", "-c", "exec php -d memory_limit=512M -S 0.0.0.0:${PORT:-8080} -t public public/index.php"]

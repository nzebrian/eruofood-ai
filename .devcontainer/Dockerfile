# EruoFood AI — development container.
# Bundles PHP 8.4 + Composer and Node 22 so a contributor can lint, test, and
# run every app. Docker itself is provided via the docker-outside-of-docker
# feature, so `make up` drives the Compose stack from inside the container.
FROM mcr.microsoft.com/devcontainers/php:8.4

# Node 22 (for the web client and the OpenAPI tooling)
RUN su vscode -c "curl -fsSL https://deb.nodesource.com/setup_22.x | bash -" 2>/dev/null || true \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs make \
    && rm -rf /var/lib/apt/lists/*

# PostgreSQL + Redis PHP extensions to match the runtime image
RUN install-php-extensions pdo_pgsql pgsql redis intl zip bcmath pcntl

WORKDIR /workspace

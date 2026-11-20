# syntax=docker/dockerfile:1

# ---- Builder ---------------------------------------------------------------
# Runs on the build machine's native platform (arm64 on the Mac), because its
# output — vendor/ (pure PHP) and public/build (static assets) — does not
# depend on the CPU architecture. Only the runtime stage targets the server.
# PHP and Node live together here because `vp build` calls
# `php artisan wayfinder:generate` before Vite bundles the frontend.
FROM --platform=$BUILDPLATFORM dunglas/frankenphp:1-php8.5-alpine AS builder

RUN apk add --no-cache nodejs npm
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so they stay cached while only app code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY . .

# Dummy values only so artisan can boot during the build. Real values come
# from .env.production at runtime and are never baked into the image.
ENV APP_ENV=production \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory:

# VITE_* is compiled into the JS bundle, so it must be known at build time.
ARG VITE_APP_NAME="AI Image Generator"
ENV VITE_APP_NAME=${VITE_APP_NAME}

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && npm run build \
    && rm -rf node_modules tests resources/js resources/css \
        bootstrap/cache/*.php storage/logs/* \
        package.json package-lock.json .npmrc pnpm-workspace.yaml \
        vite.config.ts tsconfig.json components.json phpunit.xml phpstan.neon pint.json \
        docker deploy.env.example .env.production.example

# ---- Runtime ---------------------------------------------------------------
# Targets the server's platform (linux/amd64). No extra PHP extensions are
# compiled: pdo_sqlite and opcache already ship with the base image, which
# keeps the image small and avoids slow builds under emulation.
FROM dunglas/frankenphp:1-php8.5-alpine AS runtime

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app
COPY --from=builder /app /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p database/data storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

# The base image's HEALTHCHECK probes Caddy's admin API, which
# `frankenphp php-server` does not enable. Health is checked in compose.
HEALTHCHECK NONE

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":80"]

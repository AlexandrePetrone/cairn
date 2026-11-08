#syntax=docker/dockerfile:1

# Development image only: the code is bind-mounted at runtime (compose.yaml).
FROM node:22-bookworm-slim AS node

FROM dunglas/frankenphp:1-php8.4-trixie

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# Node.js runs the Vite dev server and the production build of the assets.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git \
		postgresql-client \
		unzip
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		pdo_pgsql \
		xdebug \
		zip
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	git config --system --add safe.directory /app
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"
ENV XDEBUG_MODE=off
ENV SERVER_NAME=:80

COPY --link docker/php/app.dev.ini $PHP_INI_DIR/app.conf.d/
COPY --link docker/Caddyfile /etc/frankenphp/Caddyfile

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

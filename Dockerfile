# syntax=docker/dockerfile:1.7
FROM composer:2 AS composer

# Stage 1: Get tree-sitter from edge
FROM alpine:edge AS ts-source

ARG TREE_SITTER_TWIG_REF=7195ee573ab5c3b3bb0e91b042e6f83ac1b11104

RUN apk add --no-cache \
    tree-sitter-cli \
    tree-sitter \
    tree-sitter-php \
    tree-sitter-yaml \
    tree-sitter-javascript \
    tree-sitter-css \
    git \
    gcc \
    musl-dev

RUN set -eux; \
    mkdir -p /usr/lib/tree-sitter; \
    git clone https://github.com/gbprod/tree-sitter-twig /tmp/tree-sitter-twig; \
    git -C /tmp/tree-sitter-twig checkout "${TREE_SITTER_TWIG_REF}"; \
    gcc -O3 -shared -fPIC \
        -I/tmp/tree-sitter-twig/src \
        /tmp/tree-sitter-twig/src/parser.c \
        -o /usr/lib/libtree-sitter-twig.so; \
    rm -rf /tmp/tree-sitter-twig

# Stage 2: Final image with PHP 8.5 runtime
FROM alpine:edge

# Build-time arguments for user/group
ARG USER_ID=1000
ARG GROUP_ID=1000

# Install PHP 8.5 and extensions
RUN set -eux; \
    echo "https://dl-cdn.alpinelinux.org/alpine/edge/testing" >> /etc/apk/repositories; \
    apk add --no-cache \
        ca-certificates \
        git \
        openssh-client \
        time \
        php85 \
        php85-pdo_sqlite \
        php85-phar \
        php85-iconv \
        php85-mbstring \
        php85-openssl \
        php85-curl \
        php85-dom \
        php85-xml \
        php85-xmlwriter \
        php85-tokenizer \
        php85-simplexml \
        php85-json \
        php85-ffi \
        php85-pcntl \
        php85-posix \
        php85-meminfo \
        php85-pecl-memprof \
        php85-pecl-igbinary \
        php85-pecl-swoole \
        php85-spx \
        php85-pecl-xhprof; \
    ln -sf /usr/bin/php85 /usr/bin/php

# Keep meminfo enabled for memory testing, disable other profilers and swoole by default
RUN mkdir -p /etc/php85/conf.d.disabled && \
    for ini_name in memprof.ini swoole.ini spx.ini xhprof.ini; do \
        if [ -f "/etc/php85/conf.d/${ini_name}" ]; then \
            mv "/etc/php85/conf.d/${ini_name}" "/etc/php85/conf.d.disabled/${ini_name}"; \
        fi; \
    done

# Copy tree-sitter from Stage 1
COPY --from=ts-source /usr/bin/tree-sitter /usr/bin/tree-sitter
COPY --from=ts-source /usr/lib/libtree-sitter* /usr/lib/
COPY --from=ts-source /usr/lib/tree-sitter /usr/lib/tree-sitter

# Ensure composer uses the selected default PHP binary
ENV PHP_BINARY=/usr/bin/php85
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Create user and group matching build args
RUN addgroup -g $GROUP_ID evolver && \
    adduser -D -u $USER_ID -G evolver evolver

WORKDIR /app

# Create composer cache directory with correct ownership for evolver user
RUN mkdir -p /home/evolver/.composer/cache && \
    chown -R evolver:evolver /home/evolver/.composer

# Set COMPOSER_HOME after directory is created
ENV COMPOSER_HOME=/home/evolver/.composer

# Copy dependency manifests first so source edits do not invalidate composer install.
COPY --chown=evolver:evolver composer.json composer.lock ./

# Create vendor directory with correct ownership before switching user
RUN mkdir -p /app/vendor && chown -R evolver:evolver /app

# Install dependencies as the runtime user and keep a persistent BuildKit cache.
USER evolver

RUN --mount=type=cache,target=/home/evolver/.composer/cache,mode=0777 \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

# Copy remaining project files
USER root
COPY --chown=evolver:evolver . .

# Setup PHP configuration for OPcache and FFI preload after source files exist.
RUN set -eux; \
    php_conf_dir="/etc/php85/conf.d"; \
    php_module_dir="/usr/lib/php85/modules"; \
    echo "opcache.enable=1" > "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.enable_cli=1" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.memory_consumption=256" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.interned_strings_buffer=16" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.jit_buffer_size=0" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.jit=disable" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "memory_limit=1G" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "extension=${php_module_dir}/ffi.so" > "${php_conf_dir}/00_ffi.ini"; \
    echo "ffi.enable=1" >> "${php_conf_dir}/00_ffi.ini"; \
    echo "ffi.preload=/app/src/preload.php" >> "${php_conf_dir}/00_ffi.ini"

# Switch to evolver user for runtime and final autoload generation
USER evolver

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

# No ENTRYPOINT for maximum flexibility during dev
CMD ["php85", "bin/evolver", "serve", "--host=0.0.0.0", "--port=8080"]

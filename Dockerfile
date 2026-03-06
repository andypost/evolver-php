FROM composer:2 AS composer

# Stage 1: Get tree-sitter from edge
FROM alpine:edge AS ts-source
RUN apk add --no-cache tree-sitter-cli tree-sitter tree-sitter-php tree-sitter-yaml tree-sitter-javascript tree-sitter-css

# Stage 2: Final image with PHP 8.5 runtime
FROM alpine:edge

# Build-time arguments for user/group
ARG USER_ID=1000
ARG GROUP_ID=1000

# Install PHP 8.5 and extensions
RUN set -eux; \
    echo "https://dl-cdn.alpinelinux.org/alpine/edge/testing" >> /etc/apk/repositories; \
    apk add --no-cache \
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
        php85-meminfo \
        php85-pecl-memprof \
        php85-pecl-igbinary \
        php85-spx \
        php85-pecl-xhprof; \
    ln -sf /usr/bin/php85 /usr/bin/php

# Keep meminfo enabled for memory testing, disable other profilers by default
RUN mkdir -p /etc/php85/conf.d.disabled && \
    for ini_name in memprof.ini spx.ini xhprof.ini; do \
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
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/root/.composer
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Create user and group
RUN addgroup -g $GROUP_ID evolver && \
    adduser -D -u $USER_ID -G evolver evolver

WORKDIR /app

# Setup PHP configuration for OPcache and FFI preload
USER root
RUN set -eux; \
    php_conf_dir="/etc/php85/conf.d"; \
    php_module_dir="/usr/lib/php85/modules"; \
    echo "opcache.enable=1" > "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.enable_cli=1" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.memory_consumption=256" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.interned_strings_buffer=16" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.jit_buffer_size=0" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "opcache.jit=disable" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "memory_limit=512M" >> "${php_conf_dir}/00_opcache.ini"; \
    echo "extension=${php_module_dir}/ffi.so" > "${php_conf_dir}/00_ffi.ini"; \
    echo "ffi.enable=1" >> "${php_conf_dir}/00_ffi.ini"; \
    echo "ffi.preload=/app/src/preload.php" >> "${php_conf_dir}/00_ffi.ini"

# Note: igbinary is loaded by its own ini file from the package

# Pre-copy src for preload
COPY src ./src

# Install composer dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader --ignore-platform-reqs

# Copy remaining project files
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

USER evolver

# Keep container alive
ENTRYPOINT ["tail", "-f", "/dev/null"]

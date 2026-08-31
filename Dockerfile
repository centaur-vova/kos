# Dockerfile
FROM php:8.4-alpine

# Install build dependencies for Swoole
RUN apk add --no-cache \
    git \
    unzip \
    libstdc++ \
    libgcc \
    openssl-dev \
    pcre-dev \
    zlib-dev \
    brotli-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install Swoole from source (bypasses PECL issues)
RUN cd /tmp \
    && git clone --depth 1 https://github.com/swoole/swoole-src.git \
    && cd swoole-src \
    && phpize \
    && ./configure \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable swoole

# Install PostgreSQL
RUN apk add --no-cache \
    postgresql-dev \
    libpq \
    && docker-php-ext-install pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer

WORKDIR /app

# Install PHP dependencies
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

# Copy source code
COPY . .

EXPOSE 8080

CMD ["php", "public/index.php"]
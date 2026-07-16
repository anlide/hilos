# -----------------------------------------------------------
# Base image: PHP 8.4 CLI
# Suitable for daemon processes and CLI-based services.
# -----------------------------------------------------------
FROM php:8.4-cli

# -----------------------------------------------------------
# System dependencies for PECL "event" extension and Composer
# -----------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    libevent-dev \
    libssl-dev \
    git \
    zip \
    unzip \
 && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------
# PHP core extensions
# Install sockets BEFORE event (critical!)
# -----------------------------------------------------------
RUN docker-php-ext-install -j$(nproc) sockets pcntl posix mysqli pdo pdo_mysql

# -----------------------------------------------------------
# Ensure custom extension load order
# -----------------------------------------------------------
RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-sockets.ini \
 && echo "extension=sockets" > /usr/local/etc/php/conf.d/20-sockets.ini

# -----------------------------------------------------------
# Install PECL "event" extension AFTER sockets
# -----------------------------------------------------------
RUN pecl channel-update pecl.php.net \
 && yes "" | pecl install -o -f event \
 && rm -f /usr/local/etc/php/conf.d/docker-php-ext-event.ini \
 && echo "extension=event" > /usr/local/etc/php/conf.d/30-event.ini

# -----------------------------------------------------------
# Install Composer (copied from the official Composer image)
# -----------------------------------------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# -----------------------------------------------------------
# Application setup
# -----------------------------------------------------------
WORKDIR /app

RUN mkdir -p /var/log/hilos

# -----------------------------------------------------------
# Entrypoint: PHP as PID1 (signal propagation / graceful shutdown).
# docker.php migrates this node's schema then supervises daemon.php.
# -----------------------------------------------------------
CMD ["php", "backend/Bootstrap/docker.php"]

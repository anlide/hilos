# -----------------------------------------------------------
# Base image: PHP 8.4 CLI
# Suitable for daemon processes and CLI-based services.
# -----------------------------------------------------------
FROM php:8.4-cli

# -----------------------------------------------------------
# System dependencies for PECL "event" extension and Composer
# - libevent-dev: required to compile ext-event
# - libssl-dev: enables SSL support (optional)
# - git, zip, unzip: required for Composer installations
# - default-mysql-client: provides mysqldump, which the backup child command
#   (`backup:run`) shells out to; without it every backup fails at the dump step
# - rsync, openssh-client: what the backup agent spawns to copy an archive off
#   this machine (BACKUP_SHIP_TARGET); without them a configured destination
#   fails per transfer instead of at deployment
# -----------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    libevent-dev \
    libssl-dev \
    git \
    zip \
    unzip \
    default-mysql-client \
    rsync \
    openssh-client \
 && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------
# PHP core extensions
# Install sockets BEFORE event (critical!)
# pcntl and posix are useful for daemonization and process management.
# mysqli, pdo, and pdo_mysql are required for database operations.
# -----------------------------------------------------------
RUN docker-php-ext-install -j$(nproc) sockets pcntl posix mysqli pdo pdo_mysql

# -----------------------------------------------------------
# Ensure custom extension load order
# Remove the auto-generated .ini file and explicitly define order.
# -----------------------------------------------------------
RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-sockets.ini \
 && echo "extension=sockets" > /usr/local/etc/php/conf.d/20-sockets.ini

# -----------------------------------------------------------
# Install PECL "event" extension AFTER sockets
# - Update PECL channel to avoid cached builds
# - Force installation
# - Ensure proper loading order (after sockets)
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

# Optional: create log and runtime directories
RUN mkdir -p /var/log/hilos

# -----------------------------------------------------------
# Entrypoint
# Use PHP as PID1 to ensure signal propagation and graceful shutdown
# Note: This runs docker.php from the demo project, which will run daemon.php
# -----------------------------------------------------------
CMD ["php", "src/Bootstrap/docker.php"]

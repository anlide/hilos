# -----------------------------------------------------------
# Base image: PHP 8.4 CLI
# Suitable for daemon processes and CLI-based services.
# -----------------------------------------------------------
FROM php:8.4.27-cli@sha256:a4ae1f57e22b89a0a69c5e085b3010ee727d094e1542d249e48a01bec3c44810

# -----------------------------------------------------------
# System dependencies for PECL "event" extension and Composer
# - libevent-dev: required to compile ext-event
# - libssl-dev: enables SSL support (optional)
# - git, zip, unzip: required for Composer installations
# - default-mysql-client: mysqldump and mysql, which the backup and restore
#   children run - this demo declares the backup feature (HIL-1225), and
#   without them every run of it fails
# -----------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    libevent-dev \
    libssl-dev \
    git \
    zip \
    unzip \
    default-mysql-client \
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
# Install pinned, patched ext-event AFTER sockets from the shared build context.
# -----------------------------------------------------------
COPY --from=hilos-event . /tmp/hilos-event/
RUN sh /tmp/hilos-event/install.sh \
 && rm -rf /tmp/hilos-event

# -----------------------------------------------------------
# Install Composer (copied from the official Composer image)
# -----------------------------------------------------------
COPY --from=composer:2.11.1@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c /usr/bin/composer /usr/bin/composer

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
CMD ["php", "backend/Bootstrap/docker.php"]

# The stand's gateway for every non-mail channel (HIL-492, HIL-653).
#
# A Gateway that charges real money for real messages cannot be in a test suite, and
# a suite that skips the transport proves only that the code compiles. So the stand
# runs its own: the provider endpoints the framework clients speak to - Telegram
# Gateway and a generic HTTP SMS gateway - plus a handful of test-only routes that
# let a spec declare a number absent from Telegram and wipe the state.
#
# What it does NOT carry is a way to read what arrived. Every caught message is
# forwarded to Mailpit as a letter, so the one inbox a person already opens holds
# mail, SMS and Telegram alike, and a channel added later needs a stand endpoint
# rather than a new way to read it.
#
# It speaks real TLS, and speaks it through the framework's own server (HIL-921): the
# daemon verifies this gateway exactly as it verifies a provider in production, and an
# emulator on plain HTTP would hide the read that only an encrypted stream produces
# (HIL-732). There is no built-in server any more - it cannot do TLS at all. The
# framework is not copied in: the stack mounts it read-only at /hilos/framework, and
# bin/serve.php loads the classes it needs from there, so there are still no composer
# dependencies to keep current. What the image adds is what that server needs to run:
# the extensions below, in the daemon's own order.
#
# The version tracks the framework's own, not the reference mock's: the gateway runs
# the framework's classes, written in the same PHP as the rest of the repository, and
# an older image would not parse them - the container would not come up at all.
FROM php:8.4-cli

# libevent-dev and libssl-dev: what the PECL "event" extension is compiled against.
RUN apt-get update && apt-get install -y --no-install-recommends \
    libevent-dev \
    libssl-dev \
 && rm -rf /var/lib/apt/lists/*

# sockets BEFORE event (critical, as in the daemon's image); pcntl lets the process stop
# on SIGTERM instead of waiting out the container's stop timeout.
RUN docker-php-ext-install -j$(nproc) sockets pcntl posix

RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-sockets.ini \
 && echo "extension=sockets" > /usr/local/etc/php/conf.d/20-sockets.ini

RUN pecl channel-update pecl.php.net \
 && yes "" | pecl install -o -f event \
 && rm -f /usr/local/etc/php/conf.d/docker-php-ext-event.ini \
 && echo "extension=event" > /usr/local/etc/php/conf.d/30-event.ini

WORKDIR /app

COPY bin /app/bin
COPY src /app/src
COPY tls/server.pem tls/ca.pem /app/tls/

CMD ["php", "/app/bin/serve.php"]

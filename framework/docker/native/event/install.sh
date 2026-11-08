#!/bin/sh
# Build the reviewed PHP 8 ext-event source and its local peer-certificate patch.
set -eu

event_assets=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
event_build=$(mktemp -d)
trap 'rm -rf "$event_build"' EXIT
trap 'exit 1' HUP INT TERM

php -r 'exit(PHP_MAJOR_VERSION === 8 && extension_loaded("sockets") ? 0 : 1);'

cp "$event_assets/peer-certificate.patch" "$event_build/"
cd "$event_build"
curl --fail --location --silent --show-error \
    https://pecl.php.net/get/event-3.1.6.tgz -o event-3.1.6.tgz
sha256sum --check "$event_assets/checksums.sha256"
tar -xzf event-3.1.6.tgz
cd event-3.1.6
patch --batch --forward --fuzz=0 -p1 < ../peer-certificate.patch
phpize
./configure --with-event-core --with-event-openssl=yes --enable-event-sockets=yes
# configure rewrites the stub; use the matching, reviewed arginfo from the patch.
# Upstream's other stub signatures do not pass PHP 8.4's generator.
touch php8/php_event_arginfo.h
make -j"$(nproc)"
make install

# The caller installs sockets first and loads it before this file.
rm -f "$PHP_INI_DIR/conf.d/docker-php-ext-event.ini"
printf '%s\n' 'extension=event' > "$PHP_INI_DIR/conf.d/30-event.ini"
mkdir -p /usr/local/share/doc/hilos-event
cp "$event_assets/README.md" /usr/local/share/doc/hilos-event/README.md

php -r '
$method = new ReflectionMethod(EventBufferEvent::class, "sslGetPeerCertificate");
$type = $method->getReturnType();
if (!$method->isInternal() || !$method->isPublic() || $method->isStatic()
    || $method->getNumberOfParameters() !== 0
    || !$type instanceof ReflectionNamedType || $type->getName() !== "string" || !$type->allowsNull()) {
    throw new RuntimeException("The native peer certificate API was not installed");
}
'

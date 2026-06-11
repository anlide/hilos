#!/bin/sh
set -e

# Generate self-signed cert if SSL dir is empty (for dev/test)
if [ ! -f /etc/nginx/ssl/cert.pem ]; then
    mkdir -p /etc/nginx/ssl
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/key.pem -out /etc/nginx/ssl/cert.pem \
        -subj "/CN=localhost"
fi

# Substitute env vars in nginx config
export FRONTEND_HTML_UPSTREAM="${FRONTEND_HTML_UPSTREAM:-todo-daemon-local}"
envsubst '${FRONTEND_HTML_UPSTREAM}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

exec "$@"

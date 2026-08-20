#!/bin/sh
set -e

# A certificate with only a CN and no subjectAltName is not merely untrusted —
# browsers have rejected the CN fallback since 2017 (Chrome 58), so such a cert
# fails validation outright and a WebSocket handshake to the same origin has no
# interstitial to click through. Certificates generated before this check are in
# a named volume that outlives the image, so drop a legacy one and reissue.
if [ -f /etc/nginx/ssl/cert.pem ] && \
   ! openssl x509 -in /etc/nginx/ssl/cert.pem -noout -ext subjectAltName 2>/dev/null | grep -q "DNS:"; then
    echo "nginx: existing certificate has no subjectAltName - reissuing" >&2
    rm -f /etc/nginx/ssl/cert.pem /etc/nginx/ssl/key.pem
fi

# Generate self-signed cert if SSL dir is empty (for dev/test)
if [ ! -f /etc/nginx/ssl/cert.pem ]; then
    mkdir -p /etc/nginx/ssl
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/key.pem -out /etc/nginx/ssl/cert.pem \
        -subj "/CN=localhost" \
        -addext "subjectAltName=DNS:localhost,DNS:*.hilos,IP:127.0.0.1"
fi

# Substitute env vars in nginx config
export FRONTEND_HTML_UPSTREAM="${FRONTEND_HTML_UPSTREAM:-tasks-daemon-local}"
envsubst '${FRONTEND_HTML_UPSTREAM}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

exec "$@"

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

# The proxy in front of this nginx names the visitor in X-Real-IP, and only the
# addresses in NGINX_REAL_IP_FROM (comma-separated CIDRs) are believed. Unset or
# empty leaves the file empty: $remote_addr stays the TCP peer, which is right for
# a front door. Trusting every address would let any client name its own, so
# 0.0.0.0/0 and ::/0 are refused, as the daemon refuses them in
# HILOS_TRUSTED_PROXIES.
: > /etc/nginx/real-ip.conf
if [ -n "${NGINX_REAL_IP_FROM:-}" ]; then
    for cidr in $(printf '%s' "$NGINX_REAL_IP_FROM" | tr ',' ' '); do
        case "$cidr" in
            0.0.0.0/0|::/0)
                echo "nginx: NGINX_REAL_IP_FROM must not trust every address ($cidr)" >&2
                exit 1
                ;;
        esac
        printf 'set_real_ip_from %s;\n' "$cidr" >> /etc/nginx/real-ip.conf
    done
    printf 'real_ip_header X-Real-IP;\n' >> /etc/nginx/real-ip.conf
fi

# Substitute env vars in nginx config
export FRONTEND_HTML_UPSTREAM="${FRONTEND_HTML_UPSTREAM:-roblox-game-server-daemon-local}"
# Explicit allowlist shared with local/test/prod: nginx's own variables stay intact.
envsubst '${FRONTEND_HTML_UPSTREAM} ${HILOS_CLUSTER_WS_M1} ${HILOS_CLUSTER_WS_M2} ${HILOS_CLUSTER_WS_M3} ${HILOS_CLUSTER_HTTP_M1} ${HILOS_CLUSTER_HTTP_M2} ${HILOS_CLUSTER_HTTP_M3}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

exec "$@"

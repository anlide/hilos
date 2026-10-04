#!/usr/bin/env bash
# Keep the HAProxy agent on the cluster-only network at port 9200. Run it beside
# the member's original entrypoint; exec leaves that entrypoint as PID 1 so its
# signal and shutdown behavior stays the same.
socat TCP-LISTEN:9200,fork,reuseaddr EXEC:"bash /usr/local/bin/hilos-galera-agent.sh" &
exec "$@"

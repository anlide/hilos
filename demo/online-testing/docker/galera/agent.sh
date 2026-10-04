#!/usr/bin/env bash
# The proxy uses the same Synced check as the service healthcheck. Joiners,
# Joined members, and a donor blocked by a full copy take no new connections.
if healthcheck.sh --su-mysql --no-defaults --connect --galera_online >/dev/null 2>&1; then
    echo up
else
    echo down
fi

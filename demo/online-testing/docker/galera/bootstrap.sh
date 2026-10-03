#!/usr/bin/env bash
# Who raises the cluster of the stand's database (HIL-1230). Mounted into galera1 alone and
# run as `bash <this file>`, so it needs no execute bit; galera2 and galera3 only ever join.
#
# galera1 raises a new cluster only when neither neighbour answers on the replication port
# 4567: on the stand's first start, where they wait for galera1 to be healthy, and after the
# whole stand was stopped (`test:cluster:down` without the volumes, then `test:cluster:up`).
# With a neighbour answering it joins, the way the other two do: a galera1 restarted beside
# living members raising a cluster of its own would split the database in two.
#
# A member that stopped before the rest writes safe_to_bootstrap: 0 into its grastate.dat,
# and mariadbd then refuses to raise a cluster from it. That is rewritten to 1: the stand's
# database is disposable, and losing the tail of the writes after a hard stop is acceptable,
# a stand that cannot come up again is not.
set -euo pipefail

for peer in 10.223.0.22 10.223.0.23; do
    if timeout 1 bash -c "</dev/tcp/$peer/4567" 2>/dev/null; then
        exec docker-entrypoint.sh "$@"
    fi
done

grastate=/var/lib/mysql/grastate.dat
if [ -f "$grastate" ]; then
    sed -i 's/^safe_to_bootstrap: 0$/safe_to_bootstrap: 1/' "$grastate"
fi
exec docker-entrypoint.sh "$@" --wsrep-new-cluster

#!/usr/bin/env bash
# Who raises the Galera cluster of a stand's database. Mounted into the data member that starts
# first and run as `bash <this file>`, so it needs no execute bit; the other data members only
# ever join.
#
# HILOS_GALERA_DATA_PEERS lists the addresses of the OTHER data members, separated by spaces. This
# member raises a new cluster only when none of them answers on the replication port 4567: on the
# stand's first start, where they wait for this member to be healthy, and after the whole stand was
# stopped and started again with its data kept. With a peer answering it joins, the way the others
# do: a member restarted beside living members raising a cluster of its own would split the
# database in two.
#
# An arbitrator (garbd) is never in that list. It holds no data, so there is nothing to join it
# for, and a component of one arbitrator is never Primary: a member that found only the
# arbitrator answering and joined it would wait for a Primary component that never comes,
# instead of raising the cluster with its own data.
#
# A member that stopped before the rest writes safe_to_bootstrap: 0 into its grastate.dat, and
# mariadbd then refuses to raise a cluster from it. That is rewritten to 1: a stand's database is
# disposable, and losing the tail of the writes after a hard stop is acceptable, a stand that
# cannot come up again is not.
set -euo pipefail

: "${HILOS_GALERA_DATA_PEERS:?HILOS_GALERA_DATA_PEERS must name the other data members}"

for peer in $HILOS_GALERA_DATA_PEERS; do
    if timeout 1 bash -c "</dev/tcp/$peer/4567" 2>/dev/null; then
        exec docker-entrypoint.sh "$@"
    fi
done

grastate=/var/lib/mysql/grastate.dat
if [ -f "$grastate" ]; then
    sed -i 's/^safe_to_bootstrap: 0$/safe_to_bootstrap: 1/' "$grastate"
fi
exec docker-entrypoint.sh "$@" --wsrep-new-cluster

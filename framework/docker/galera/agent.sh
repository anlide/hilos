#!/usr/bin/env bash
# What a data member answers the proxy's agent check: `up` while it may take new connections,
# `down` otherwise. Connections already open to a member are left alone either way.
#
# Up while Synced (wsrep_local_state 4) and while Donor/Desynced (2). With two data members the
# one that survives the other is at once the donor of the full copy to the returning member and
# the only SQL backend left; an agent that said up only while Synced would take it off the proxy
# for the whole copy, and the database would go dark exactly when the other member came back.
# The donor keeps answering because the copy is made by mariabackup (galera.cnf), and a read
# there stays causal: the READ bit of wsrep_sync_wait makes it wait until the member has applied
# what the cluster committed.
#
# Down while Joining (1) or Joined (3) - such a member has not caught up yet - and whenever the
# server does not answer.
#
# Read over the socket as the operating system user mysql, the way the image's
# `healthcheck.sh --su-mysql` signs in: mysql@localhost through unix_socket needs no password,
# and every member has it.
state=$(gosu mysql mariadb --no-defaults --skip-ssl --protocol socket -B --skip-column-names \
    -e "SELECT VARIABLE_VALUE FROM information_schema.GLOBAL_STATUS WHERE VARIABLE_NAME = 'WSREP_LOCAL_STATE'" \
    2>/dev/null)
case "$state" in
    2 | 4) echo up ;;
    *) echo down ;;
esac

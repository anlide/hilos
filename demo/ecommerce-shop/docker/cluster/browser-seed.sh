#!/bin/sh
set -eu

only_marker() {
    [ -f "$1" ] && [ ! -L "$1" ] && [ ! -e "$2" ] && [ ! -L "$2" ]
}

if only_marker /stand-seed/pending /stand-seed/ready; then
    php backend/Bootstrap/cli.php test:db:reset
    mv /stand-seed/pending /stand-seed/ready
elif only_marker /stand-seed/ready /stand-seed/pending; then
    echo 'Browser fixtures already prepared.'
else
    echo 'Unrecognized browser database. Start a fresh fixture with the cluster harness down --volumes.' >&2
    exit 1
fi

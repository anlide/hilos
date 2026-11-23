#!/bin/sh
set -eu

# MariaDB runs this only while initializing a fresh data directory.
mkdir -p /var/lib/mysql/.browser-seed
touch /var/lib/mysql/.browser-seed/pending

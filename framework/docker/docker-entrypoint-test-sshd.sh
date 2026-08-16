#!/bin/sh
set -e

# Receiver side of BACKUP_SHIP_TARGET for the framework test stand.
#
# Everything a client needs is generated here on start and published through the
# shared volume: the key pair, the known_hosts line pinning this container, and a
# marker saying both are complete. Nothing is baked into the image and nothing is
# committed to the repository — a private key that lives in git is a private key
# that ends up trusted somewhere it was never meant to be, and a stand is not a
# good enough reason to keep one.

KEY_DIR="${SHIP_KEY_DIR:-/ship-keys}"
SHIP_USER="${SHIP_USER:-shipper}"
SHIP_ROOT="${SHIP_ROOT:-/backups}"
SHIP_HOSTNAME="${SHIP_HOSTNAME:-sshd-framework-test}"

READY_MARKER="$KEY_DIR/ready"
CLIENT_KEY="$KEY_DIR/id_ed25519"
KNOWN_HOSTS="$KEY_DIR/known_hosts"

# The marker goes first, so a client that woke up during a restart waits for the
# new pair instead of reading the old one half-replaced.
rm -f "$READY_MARKER"
mkdir -p "$KEY_DIR" /run/sshd

# Host keys are regenerated per start, which is why known_hosts is republished
# below: a client pinning a key from a previous container has to be told once.
rm -f /etc/ssh/ssh_host_*
ssh-keygen -A >/dev/null

rm -f "$CLIENT_KEY" "$CLIENT_KEY.pub"
ssh-keygen -t ed25519 -N '' -C 'hilos-framework-test' -f "$CLIENT_KEY" >/dev/null
chmod 600 "$CLIENT_KEY"

mkdir -p "/home/$SHIP_USER/.ssh"
cp "$CLIENT_KEY.pub" "/home/$SHIP_USER/.ssh/authorized_keys"
chmod 700 "/home/$SHIP_USER/.ssh"
chmod 600 "/home/$SHIP_USER/.ssh/authorized_keys"
chown -R "$SHIP_USER:$SHIP_USER" "/home/$SHIP_USER/.ssh"

# Only the key type and the key itself; the comment field of a .pub file is not
# part of a known_hosts line.
awk -v host="$SHIP_HOSTNAME" '{ print host, $1, $2 }' \
    /etc/ssh/ssh_host_ed25519_key.pub > "$KNOWN_HOSTS"

# rsync creates the last missing component of a destination path and no more, so
# the root has to exist before the first transfer names a scope directory under it.
mkdir -p "$SHIP_ROOT"
chown "$SHIP_USER:$SHIP_USER" "$SHIP_ROOT"

echo 'ready' > "$READY_MARKER"

exec /usr/sbin/sshd -D -e

#!/bin/sh
set -eu

refuse() {
    printf 'test-writer-exec: %s\n' "$*" >&2
    exit 1
}

[ "$#" -ge 3 ] || refuse 'usage: test-writer-exec.sh <owner-root> -- <command> [args...]'
owner_root=$1
shift
[ "$1" = -- ] || refuse 'expected -- before command'
shift
[ -d "$owner_root" ] && [ ! -L "$owner_root" ] || refuse "not a bind-root directory: $owner_root"
owner=$(stat -c '%u:%g' -- "$owner_root") || refuse "cannot stat owner: $owner_root"

# Numeric identities work without passwd entries; no supplementary root groups
# survive the switch. exec and --skip-chdir preserve PID 1, argv, cwd and status.
exec chroot --userspec="+${owner%:*}:+${owner#*:}" --groups= --skip-chdir / "$@"

#!/bin/sh
set -eu
set -f

refuse() {
    printf 'test-writer: %s\n' "$*" >&2
    exit 1
}

owner_root=${HILOS_TEST_OWNER_ROOT:-}
case "$owner_root" in
    /app|/hilos) ;;
    *) refuse "expected bind-root /app or /hilos: $owner_root" ;;
esac
[ -d "$owner_root" ] && [ ! -L "$owner_root" ] || refuse "not a bind-root directory: $owner_root"
owner=$(stat -c '%u:%g' -- "$owner_root") || refuse "cannot stat owner: $owner_root"
[ "$#" -gt 0 ] || refuse 'command is required'

# Space-separated paths are literal: neither glob expansion nor eval is used.
IFS=' '
write_dirs=${HILOS_TEST_WRITE_DIRS:-}
own_dirs=${HILOS_TEST_OWN_DIRS:-}
has_output=false

validate_path() (
    path=$1
    case "$path" in
        /*) ;;
        *) refuse "path must be absolute: $path" ;;
    esac
    case "$path" in
        /|/app|/hilos|*/|*//*|*/./*|*/.|*/../*|*/..|*/.git|*/.git/*|*[[:space:]]*)
            refuse "unsafe output path: $path" ;;
    esac
    rest=${path#/}
    current=
    while [ -n "$rest" ]; do
        part=${rest%%/*}
        current=$current/$part
        [ ! -L "$current" ] || refuse "symlink in output path: $current"
        if [ -e "$current" ]; then
            [ -d "$current" ] || refuse "not a directory: $current"
        fi
        case "$rest" in
            */*) rest=${rest#*/} ;;
            *) rest= ;;
        esac
    done
)

# Validate the complete declaration before mkdir/chown, including optional
# parents. Only /app/data is an isolated stand root; local data directories
# cannot be declared as recursive outputs.
for path in $write_dirs; do
    has_output=true
    case "$path" in
        /app/data)
            [ "$owner_root" = /app ] || refuse "stand data requires bind-root /app: $path" ;;
        */data) refuse "declare data outputs separately: $path" ;;
    esac
    validate_path "$path"
done
[ "$has_output" = true ] || refuse 'HILOS_TEST_WRITE_DIRS is required and must not be empty'
for path in $own_dirs; do
    validate_path "$path"
done

own_directory() (
    path=$1
    mode=$(stat -c '%a' -- "$path") || refuse "cannot stat directory: $path"
    chown -h -- "$owner" "$path" || refuse "cannot chown directory: $path"
    chmod "$mode" -- "$path" || refuse "cannot preserve directory mode: $path"
)

prepare_directory() (
    rest=${1#/}
    current=
    while [ -n "$rest" ]; do
        part=${rest%%/*}
        current=$current/$part
        [ ! -L "$current" ] || refuse "symlink in output path: $current"
        if [ ! -d "$current" ]; then
            mkdir -- "$current" || refuse "cannot mkdir: $current"
            # Missing ancestors belong to this output too; do not leave a new
            # root-owned parent behind when the bind-root belongs to nonroot.
            own_directory "$current"
        fi
        case "$rest" in
            */*) rest=${rest#*/} ;;
            *) rest= ;;
        esac
    done
    own_directory "$1"
)

for path in $own_dirs; do
    prepare_directory "$path"
done
for path in $write_dirs; do
    prepare_directory "$path"
    # Physical traversal never enters a linked directory. chown -h changes a
    # symlink itself, while its target (possibly outside the output) stays put.
    # Restore mode bits that chown can clear, including setuid/setgid.
    find -P "$path" -name .git -prune -o -uid 0 -exec sh -eu -c '
        owner=$1
        shift
        for path do
            if [ -L "$path" ]; then
                chown -h -- "$owner" "$path" || exit 1
            else
                mode=$(stat -c "%a" -- "$path") || exit 1
                chown -h -- "$owner" "$path" || exit 1
                chmod "$mode" -- "$path" || exit 1
            fi
        done
    ' sh "$owner" {} + || refuse "cannot prepare output: $path"
done

exec sh "$(dirname -- "$0")/test-writer-exec.sh" "$owner_root" -- "$@"

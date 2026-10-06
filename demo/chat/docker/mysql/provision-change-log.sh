#!/bin/sh

set -eu

primary_database=${MYSQL_DATABASE:?MYSQL_DATABASE is required}
app_user=${MYSQL_USER:?MYSQL_USER is required}

if ! printf '%s' "$primary_database" | grep -Eq '^[A-Za-z0-9_%-]+$'; then
    printf '%s\n' "Invalid primary database name: $primary_database" >&2
    exit 1
fi
if ! printf '%s' "$app_user" | grep -Eq '^[A-Za-z0-9_%-]+$'; then
    printf '%s\n' "Invalid application database user: $app_user" >&2
    exit 1
fi

journal_database="${primary_database}-change-log"
if [ "${#journal_database}" -gt 64 ]; then
    printf '%s\n' "Change log database name is too long: $journal_database" >&2
    exit 1
fi

# GRANT treats '_' and '%' as patterns even inside backticks; escape them there.
grant_database=$(printf '%s' "$journal_database" | sed 's/[_%]/\\&/g')
export MYSQL_PWD=${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}

mariadb -uroot -e "CREATE DATABASE IF NOT EXISTS \`$journal_database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mariadb -uroot -e "GRANT ALL PRIVILEGES ON \`$grant_database\`.* TO '$app_user'@'%';"

<?php

declare(strict_types=1);

require_once __DIR__ . '/framework-pieces.php';

$databases = [];
for ($piece = 1; $piece <= FRAMEWORK_INTEGRATION_PIECES; $piece++) {
    $databases[] = frameworkPieceDatabase($piece);
}

$script = <<<'SH'
    set -e
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    for database do
        mariadb -uroot -e "DROP DATABASE IF EXISTS \`$database\`; CREATE DATABASE \`$database\`; GRANT ALL PRIVILEGES ON \`$database\`.* TO '$MYSQL_USER'@'%';"
    done
    SH;

chdir(dirname(__DIR__));
$command = 'docker compose -f framework/docker/docker-compose.yml exec -T mysql-framework-test sh -c '
    . escapeshellarg($script) . ' sh ' . implode(' ', array_map('escapeshellarg', $databases));
passthru($command, $status);
if ($status !== 0) {
    exit($status);
}
fwrite(STDOUT, 'framework databases: ' . implode(', ', $databases) . " — recreated\n");

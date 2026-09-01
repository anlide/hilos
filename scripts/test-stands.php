<?php

declare(strict_types=1);

/**
 * The stands a test run raises, as a list instead of five spellings of the same teardown.
 *
 * `scripts/stand-teardown.php` knows HOW a stand is dropped and `scripts/down-stands.php`
 * is the command that walks them; this file only says WHO exists and in what order. A new
 * demo is an entry here, not an edit to anyone's control flow — the same split
 * `scripts/test-suite.php` already uses for steps.
 *
 * A stand is:
 *   id           the name it is called by, both in an argument and in the printed line.
 *   cwd          relative to the repository root; the compose command runs from there.
 *   composeFile  relative to `cwd`.
 *   project      the compose project name, read off the file's own `name:` line. This is
 *                what docker labels every container and network of the stand with, and
 *                therefore what the residue is looked up by.
 *   mode         `project` drops the whole compose project; `named` touches only the
 *                containers listed here and leaves the rest of the project standing.
 *   services     `named` only: service names, because `docker compose rm` addresses
 *                services.
 *   containers   `named` only: container names, because `docker ps --filter name`
 *                addresses containers. Two lists rather than one because the two differ
 *                for three of the four entries below (`hilos-cli-test` is the container
 *                `hilos-cli-framework-test`), and a single list would silently take down
 *                nothing: compose answers an unknown service with `no such service` and
 *                keeps going.
 */

return [
    // WHY THIS ONE IS NAMED AND NOT `project`: the owner's preview stand lives in the SAME
    // compose project as the test one. `hilos-dev-dns` (framework/docker/docker-compose.yml:113),
    // `hilos-preview-caddy` (:128) and `hilos-preview-control` (:150) run for weeks at a time
    // out of this very file, so `down --remove-orphans` on the project would take the
    // home.hilos console down with the stand — and the owner would find that out by opening
    // the console, not by reading a run log. Proposal P-199 splits the two projects apart and
    // retires this exception; until it lands, the four names below are the whole stand.
    [
        'id' => 'framework',
        'cwd' => '.',
        'composeFile' => 'framework/docker/docker-compose.yml',
        'project' => 'hilos-framework',
        'mode' => 'named',
        'services' => ['mysql-framework-test', 'sshd-framework-test', 'hilos-cli-test', 'hilos-frontend-cli'],
        'containers' => [
            'hilos-mysql-framework-test',
            'hilos-sshd-framework-test',
            'hilos-cli-framework-test',
            'hilos-frontend-cli',
        ],
    ],
    [
        'id' => 'chat',
        'cwd' => 'demo/chat',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-chat-test',
        'mode' => 'project',
        'services' => [],
        'containers' => [],
    ],
    [
        'id' => 'tasks',
        'cwd' => 'demo/tasks',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-tasks-test',
        'mode' => 'project',
        'services' => [],
        'containers' => [],
    ],
    [
        'id' => 'simple-poll',
        'cwd' => 'demo/simple-poll',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-simple-poll-test',
        'mode' => 'project',
        'services' => [],
        'containers' => [],
    ],
    [
        'id' => 'cluster',
        'cwd' => 'demo/cluster',
        'composeFile' => 'docker/docker-compose.cluster.yml',
        'project' => 'hilos-cluster',
        'mode' => 'project',
        'services' => [],
        'containers' => [],
    ],
];

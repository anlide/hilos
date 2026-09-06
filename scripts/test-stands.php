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
 *   mode         `project` drops the whole compose project; `profile` drops only the
 *                profiles named below and leaves the rest of the project standing.
 *   profiles     `profile` only: the compose profiles the stand's services sit behind. The
 *                services themselves are NOT listed: compose resolves them out of the file
 *                by these profiles, so one added to a profile is taken down without an edit
 *                here. Empty on a `project` stand, which owns its whole file.
 *   networks     `profile` only: the FULL docker names of the networks the stand owns, since
 *                the project label is shared with whatever else lives in the file. A network
 *                without its own `name:` is called the project name, an underscore and the
 *                key it carries in the file — hence
 *                `hilos-framework_hilos-framework-test-network` for the key
 *                `hilos-framework-test-network` at
 *                `framework/docker/docker-compose.yml:281`. Empty on a `project` stand, which
 *                finds its networks by project label.
 */

return [
    [
        'id' => 'framework',
        'cwd' => '.',
        'composeFile' => 'framework/docker/docker-compose.yml',
        'project' => 'hilos-framework',
        'mode' => 'profile',
        'profiles' => ['test', 'frontend'],
        'networks' => ['hilos-framework_hilos-framework-test-network'],
    ],
    [
        'id' => 'chat',
        'cwd' => 'demo/chat',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-chat-test',
        'mode' => 'project',
        'profiles' => [],
        'networks' => [],
    ],
    [
        'id' => 'tasks',
        'cwd' => 'demo/tasks',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-tasks-test',
        'mode' => 'project',
        'profiles' => [],
        'networks' => [],
    ],
    [
        'id' => 'polls',
        'cwd' => 'demo/polls',
        'composeFile' => 'docker/docker-compose.test.yml',
        'project' => 'hilos-polls-test',
        'mode' => 'project',
        'profiles' => [],
        'networks' => [],
    ],
    [
        'id' => 'cluster',
        'cwd' => 'demo/cluster',
        'composeFile' => 'docker/docker-compose.cluster.yml',
        'project' => 'hilos-cluster',
        'mode' => 'project',
        'profiles' => [],
        'networks' => [],
    ],
];

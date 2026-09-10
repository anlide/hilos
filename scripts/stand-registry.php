<?php

declare(strict_types=1);

/**
 * Finding the record of a test stand, and building the docker arguments that record implies.
 *
 * Three neighbours share this subject and split it cleanly: `scripts/test-stands.php` is the
 * data — WHO exists; `scripts/stand-teardown.php` is how a stand is DROPPED; this file is how a
 * stand is FOUND and what its record turns into on a docker command line. The split is what lets
 * the snapshot collector name a stand without depending on the teardown: collecting evidence and
 * removing containers are opposite errands, and one must never load the other.
 *
 * Before this file the registry was required in three places by hand (`scripts/down-stands.php`,
 * and twice inside `scripts/run-test-suite.php`) and the snapshot would have been the fourth.
 *
 * This file only declares functions and executes nothing, so that every one of those callers can
 * require it.
 */

/**
 * Every stand the run knows about, in the order the list gives them.
 *
 * @param string $root Repository root.
 * @return array<int, array{id: string, cwd: string, composeFile: string, project: string,
 *     mode: string, profiles: array<int, string>, networks: array<int, string>}>
 */
function standRegistry(string $root): array
{
    return require $root . '/scripts/test-stands.php';
}

/**
 * The record of one stand, or null when nothing in the registry answers to that id.
 *
 * Null rather than a thrown error: both callers — the snapshot and the teardown after a step —
 * have something to say about an unknown stand and nothing to gain from stopping the run over it.
 *
 * @param string $root Repository root.
 * @param string $id The id a step or a command line named.
 * @return array{id: string, cwd: string, composeFile: string, project: string, mode: string,
 *     profiles: array<int, string>, networks: array<int, string>}|null
 */
function standById(string $root, string $id): ?array
{
    foreach (standRegistry($root) as $stand) {
        if ($stand['id'] === $id) {
            return $stand;
        }
    }

    return null;
}

/**
 * The `--profile` flags naming what a `profile` stand owns.
 *
 * One flag per profile: compose takes the option repeatedly and has no list form for it.
 *
 * @param array<int, string> $profiles
 */
function standProfileFlags(array $profiles): string
{
    return implode(' ', array_map(
        static fn(string $profile): string => '--profile ' . escapeshellarg($profile),
        $profiles,
    ));
}

/**
 * The compose command that names the services a `profile` stand's profiles hold, or null for a
 * `project` stand, whose containers are already narrow enough at the project label.
 *
 * This is the ONLY thing `--profile` narrows on a read: `docker compose ps` and `docker compose
 * exec` both ignore the flag and answer for the whole project (measured on compose v5.4.0), so
 * everything that has to see one stand inside a shared file asks here first and names the services
 * it got back.
 *
 * @param array{composeFile: string, mode: string, profiles: array<int, string>} $stand
 */
function standServicesCommand(array $stand): ?string
{
    if ($stand['mode'] !== 'profile') {
        return null;
    }

    return 'docker compose -f ' . escapeshellarg($stand['composeFile']) . ' '
        . standProfileFlags($stand['profiles']) . ' config --services';
}

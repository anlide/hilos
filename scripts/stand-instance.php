<?php

declare(strict_types=1);

/**
 * Several copies of every test stand on one host, one per working tree, and the names that keep
 * them apart.
 *
 * A stand instance is a number. The default instance has no number: it is the stand as it always
 * was, with exactly the compose projects, container names, volumes, networks, subnets and host
 * ports written in the compose files. Instance N moves all of them at once through environment
 * variables that the compose files read:
 *
 *   HILOS_STAND_SUFFIX        appended to every compose project, container, volume and network
 *                             name, and to the cluster databases' directories under /dev/shm;
 *   HILOS_STAND_NET_<STAND>   the second octet of that stand's 10.x.0.0/16 network, and so of
 *                             every fixed address on it; octets go in blocks, see
 *                             STAND_INSTANCE_BLOCKS;
 *   the host-port variables   set to 0, so docker binds whichever port is free. Nothing on the
 *                             host reaches a stand through a published port; the ports serve a
 *                             person at a browser, and an instance has no such person.
 *
 * `scripts/stand-instance-env.php <N>` prints these as shell exports. Everything that runs in that
 * environment — the suite, a demo's composer script, a teardown — lands on instance N and leaves
 * the others alone, the default one included.
 *
 * Service keys are NOT renamed: they are the host names a stand's services reach each other by,
 * and every stand has its own network, so the same key in two instances never meets.
 */

/** The environment variable every stand name of an instance ends with. */
const STAND_INSTANCE_SUFFIX_VAR = 'HILOS_STAND_SUFFIX';

/** What an instance suffix starts with, before the number: `chat-mysql-test-s2`. */
const STAND_INSTANCE_SUFFIX_PREFIX = '-s';

/** The lowest instance number. */
const STAND_INSTANCE_MIN = 1;

/** The highest instance number: one digit keeps every suffixed name short. */
const STAND_INSTANCE_MAX = 9;

/**
 * Network octets of the instances, one block per set of stands.
 *
 * The key is the octet where instance 1 of the block starts. The value is the stands of the
 * block: the variable that moves the network, and the default octet the compose file carries.
 * Instance N starts (N - 1) times the stand count above the key.
 *
 * A recorded block does not change: not its order, not its composition, not its first octet.
 * A tree that has not merged a later block yet counts the same octets for every stand it knows,
 * and such trees run side by side for hours.
 *
 * A new set of stands opens its own block, on octets no instance of another block covers and
 * below the smallest default (186), outside the pools docker assigns from. Free are 1-29 and
 * 174-184; 185 is left. Three cluster stands need 27 in a row, which 1-29 holds; after them
 * there is room for exactly one stand (174-182). Past that, drop the rule that a block stays
 * below 186, or narrow the networks.
 *
 * The second block holds six ordinary demos, whose compose files arrive with their activation
 * leaves. Until then nobody reads their variables.
 */
const STAND_INSTANCE_BLOCKS = [
    30 => [
        'HILOS_STAND_NET_CHAT' => 186,
        'HILOS_STAND_NET_TASKS' => 187,
        'HILOS_STAND_NET_POLLS' => 188,
        'HILOS_STAND_NET_BINANCE_BTC_TRACKER' => 211,
        'HILOS_STAND_NET_ECOMMERCE_SHOP' => 212,
        'HILOS_STAND_NET_ONLINE_TESTING' => 213,
        'HILOS_STAND_NET_BINANCE_BTC_TRACKER_CLUSTER' => 221,
        'HILOS_STAND_NET_ECOMMERCE_SHOP_CLUSTER' => 222,
        'HILOS_STAND_NET_ONLINE_TESTING_CLUSTER' => 223,
        'HILOS_STAND_NET_FRAMEWORK' => 189,
    ],
    120 => [
        'HILOS_STAND_NET_PMD' => 214,
        'HILOS_STAND_NET_ROBLOX_GAME_SERVER' => 215,
        'HILOS_STAND_NET_SOLAR_SYSTEM_MODEL' => 216,
        'HILOS_STAND_NET_FILE_GALLERY_MANAGER' => 217,
        'HILOS_STAND_NET_PREFERANS' => 218,
        'HILOS_STAND_NET_CONCERT_TICKETS' => 219,
    ],
];

/** The host-port variables of the test stands, which an instance leaves to docker. */
const STAND_INSTANCE_PORT_VARS = [
    'MYSQL_TEST_HOST_PORT',
    'HTTP_TEST_PORT',
    'WORKER_TEST_PORT',
    'WEBSOCKET_TEST_PORT',
    'NGINX_TEST_HTTP_PORT',
    'NGINX_TEST_HTTPS_PORT',
    'MYSQL_FRAMEWORK_TEST_HOST_PORT',
];

/** The host port docker reads as "whichever is free". */
const STAND_INSTANCE_ANY_PORT = '0';

/**
 * The suffix of the instance this process runs in: empty in the default instance.
 *
 * @return string What every stand name of this instance ends with.
 */
function standInstanceSuffix(): string
{
    $suffix = getenv(STAND_INSTANCE_SUFFIX_VAR);

    // external-boundary: an unset variable is the default instance, whose names carry no suffix
    return $suffix === false ? '' : $suffix;
}

/**
 * The environment that puts a process on instance `$instance`.
 *
 * @param int $instance The instance number, from STAND_INSTANCE_MIN to STAND_INSTANCE_MAX.
 * @return array<string, string> Variable name to value.
 * @throws InvalidArgumentException When the number names no instance.
 */
function standInstanceEnvironment(int $instance): array
{
    if ($instance < STAND_INSTANCE_MIN || $instance > STAND_INSTANCE_MAX) {
        throw new InvalidArgumentException(
            'A stand instance is a number from ' . STAND_INSTANCE_MIN . ' to ' . STAND_INSTANCE_MAX . ", not {$instance}.",
        );
    }

    $environment = [STAND_INSTANCE_SUFFIX_VAR => STAND_INSTANCE_SUFFIX_PREFIX . $instance];
    foreach (STAND_INSTANCE_BLOCKS as $first => $stands) {
        $start = $first + ($instance - 1) * count($stands);
        foreach (array_keys($stands) as $position => $variable) {
            $environment[$variable] = (string) ($start + $position);
        }
    }
    foreach (STAND_INSTANCE_PORT_VARS as $variable) {
        $environment[$variable] = STAND_INSTANCE_ANY_PORT;
    }

    return $environment;
}

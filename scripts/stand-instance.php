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
 *                             every fixed address on it;
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
 * The second octet of each stand's network in the default instance, by the variable that moves
 * it. These are the defaults the compose files carry; an instance never lands on one of them.
 */
const STAND_INSTANCE_DEFAULT_OCTETS = [
    'HILOS_STAND_NET_CHAT' => 186,
    'HILOS_STAND_NET_TASKS' => 187,
    'HILOS_STAND_NET_POLLS' => 188,
    'HILOS_STAND_NET_BINANCE_BTC_TRACKER' => 211,
    'HILOS_STAND_NET_ECOMMERCE_SHOP' => 212,
    'HILOS_STAND_NET_ONLINE_TESTING' => 213,
    'HILOS_STAND_NET_BINANCE_BTC_TRACKER_CLUSTER' => 221,
    'HILOS_STAND_NET_ECOMMERCE_SHOP_CLUSTER' => 222,
    'HILOS_STAND_NET_ONLINE_TESTING_CLUSTER' => 223,
];

/**
 * Where the octets of the instances begin. Everything from here to the last instance's block lies
 * below the default stands (186 and up) and below the local stacks (196 to 203), and outside the
 * address pools docker assigns from on its own (172.16.0.0/12, 192.168.0.0/16).
 */
const STAND_INSTANCE_OCTET_BASE = 20;

/** Octets one instance holds: one per stand, with room for stands not yet written. */
const STAND_INSTANCE_OCTETS_PER_INSTANCE = 10;

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
    $first = STAND_INSTANCE_OCTET_BASE + $instance * STAND_INSTANCE_OCTETS_PER_INSTANCE;
    foreach (array_keys(STAND_INSTANCE_DEFAULT_OCTETS) as $position => $variable) {
        $environment[$variable] = (string) ($first + $position);
    }
    foreach (STAND_INSTANCE_PORT_VARS as $variable) {
        $environment[$variable] = STAND_INSTANCE_ANY_PORT;
    }

    return $environment;
}

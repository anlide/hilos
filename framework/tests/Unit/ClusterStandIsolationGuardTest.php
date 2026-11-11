<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-instance.php';

/**
 * Holds every cluster stand to what a stand instance counts on: its names carry the instance's
 * suffix, its addresses read the instance's octet, and its nodes publish nothing on the host.
 *
 * A forgotten suffix is invisible while one instance stands. The stand comes up and its scenarios
 * pass; only when two instances meet does a container name collide, a subnet overlap or two
 * fleets share one database directory, and that happens in somebody else's run. So it is checked
 * here rather than left to that moment.
 *
 * This is a check of the text of the files, not of the model compose resolves out of them: it
 * follows no include, extends or env file, and only a full run proves the resolved model. The
 * files are read line by line because the repository has no YAML parser, the same way the demo
 * compose guard reads them.
 *
 * A demo without a `docker/docker-compose.cluster.yml` is skipped in silence: most demos have no
 * cluster stand. The `docker/cluster/standalone.env.example` of a stack is not read either: it
 * documents a deployment on real servers, where these variables do not exist.
 */
final class ClusterStandIsolationGuardTest extends TestCase
{
    /** The compose text that appends the instance suffix to a name. */
    private const string SUFFIX = '${' . STAND_INSTANCE_SUFFIX_VAR . ':-}';

    /** A key at the top level of a compose file. */
    private const string TOP_LEVEL_KEY = '/^([A-Za-z0-9_.-]+):/';

    /** The line that names the compose project. */
    private const string PROJECT_NAME = '/^name:/';

    /** A reference to a directory under /dev/shm, up to where its path ends. */
    private const string SHM_PATH = '~/dev/shm/(?:\$\{[^{}]*\}|[^\s:"\'{}$])*~';

    /** An address whose second octet is read from a variable; the capture is what `${...}` holds. */
    private const string ADDRESS = '/(?<![\d.])10\.\$\{([^}]*)\}\./';

    /** An address whose second octet is written out. */
    private const string BARE_ADDRESS = '/(?<![\d.])10\.\d+\./';

    /** Path ending of the part that binds a stack's services to this host. */
    private const string LOCAL_PART = '/docker/cluster/local.yml';

    /**
     * A `file:` naming any part, quoted or not and under any path: the first key of its line, as in a
     * block `extends:`, or a key of a one-line mapping such as `{file: ./node.yml, service: node}`.
     * Anywhere else on a line - `env_file:`, `dockerfile:`, `max-file:`, a path, a comment - it names none.
     */
    private const string EXTENDS_PART = '/(?:^\s*|[{,]\s*)file:\s*["\']?[^\s"\',}]+/';

    /** The line that keeps a part's standalone ports off the host. */
    private const string PORTS_RESET = '    ports: !reset []';

    /** Any `ports:` key of a compose file. */
    private const string PORTS_KEY = '/^\s*ports:/';

    /** A teardown finds a stack through its registry row, so a stack without one is never taken down. */
    public function testEveryClusterStackHasARegistryRow(): void
    {
        $registry = file($this->repositoryRoot() . '/scripts/test-stands.php', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($registry, 'scripts/test-stands.php is unreadable');
        $registry = array_map('trim', $registry);

        foreach ($this->clusterStacks() as $demo => $files) {
            $id = $demo . '-cluster';
            $index = array_search("'id' => '{$id}',", $registry, true);
            $this->assertIsInt($index, array_key_first($files) . ":1: this stack has no row {$id} in scripts/test-stands.php");

            $this->assertSame(
                [
                    "'cwd' => 'demo/{$demo}',",
                    "'composeFile' => 'docker/docker-compose.cluster.yml',",
                    "'project' => 'hilos-{$demo}-cluster' . standInstanceSuffix(),",
                    "'mode' => 'project',",
                ],
                array_slice($registry, $index + 1, 4),
                'scripts/test-stands.php:' . ($index + 1) . ": the row {$id} is not the row of a cluster stack",
            );
        }
    }

    /** Every name docker holds host-wide, and every directory under /dev/shm, belongs to one instance. */
    public function testEveryClusterStackSuffixesItsNames(): void
    {
        foreach ($this->clusterStacks() as $files) {
            foreach ($files as $relativePath => $path) {
                foreach ($this->namingLinesOf($this->linesOf($path)) as $number => $line) {
                    $this->assertStringContainsString(self::SUFFIX, $line, "{$relativePath}:{$number}: this name carries no instance suffix");
                }
            }

            $composeRoot = array_key_first($files);
            $this->assertNotSame(
                [],
                preg_grep(self::PROJECT_NAME, $this->linesOf($files[$composeRoot])),
                "{$composeRoot}:1: this stack does not name its compose project",
            );

            foreach ($this->linesOfStack($files) as $place => $line) {
                preg_match_all(self::SHM_PATH, $line, $matches);
                foreach ($matches[0] as $reference) {
                    $this->assertStringContainsString(self::SUFFIX, $reference, "{$place}: this directory carries no instance suffix");
                }
            }
        }
    }

    /** A stack on an octet of another stack's variable, or on a written-out one, overlaps an instance. */
    public function testEveryClusterAddressReadsItsOwnBlockVariable(): void
    {
        $defaults = array_merge(...array_values(STAND_INSTANCE_BLOCKS));

        foreach ($this->clusterStacks() as $demo => $files) {
            $variable = 'HILOS_STAND_NET_' . strtoupper(str_replace('-', '_', $demo)) . '_CLUSTER';

            foreach ($this->linesOfStack($files) as $place => $line) {
                $this->assertSame(0, preg_match(self::BARE_ADDRESS, $line), "{$place}: this address writes its octet out");

                preg_match_all(self::ADDRESS, $line, $matches);
                foreach ($matches[1] as $octet) {
                    $this->assertArrayHasKey($variable, $defaults, "{$place}: {$variable} is not in the octet table");
                    $this->assertSame($variable . ':-' . $defaults[$variable], $octet, "{$place}: this address does not read its stack's octet");
                }
            }
        }
    }

    /** Every service of a local stand is reached through its network, so a published port only collides. */
    public function testNoLocalClusterServicePublishesAHostPort(): void
    {
        $files = array_filter(
            array_merge(...array_values($this->clusterStacks())),
            static fn(string $relativePath): bool => str_ends_with($relativePath, self::LOCAL_PART),
            ARRAY_FILTER_USE_KEY,
        );

        $problems = [];
        $services = 0;
        foreach ($files as $relativePath => $path) {
            $lines = $this->linesOf($path);
            $problems = array_merge($problems, $this->portProblemsOf($relativePath, $lines));
            $services += count(array_filter(
                $this->servicesOf($lines),
                static fn(array $body): bool => preg_grep(self::EXTENDS_PART, $body) !== [],
            ));
        }

        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
        $this->assertGreaterThan(0, $services, 'no local cluster service was found at all - the reader stopped seeing them');
    }

    /** A part no local stand extends yet is a part all the same, and a `file:` of another key, a path or a comment names none. */
    public function testAServiceExtendingAnyPartResetsItsPorts(): void
    {
        $local = 'demo/shop/docker/cluster/local.yml';
        $balancer = [
            1 => 'services:',
            2 => '  balancer:',
            3 => '    extends: {file: ./haproxy.yml, service: haproxy}',
        ];
        $mailer = [
            5 => '  mailer:',
            6 => '    image: axllent/mailpit',
            7 => '    env_file: ./mailer.env',
            8 => '    build: {context: ., dockerfile: mailer.Dockerfile}',
            9 => '    logging:',
            10 => '      options:',
            11 => '        max-file: "3"',
            12 => '    volumes:',
            13 => '      - ./secrets/file:/run/x:ro  # the secret file: read-only',
        ];
        $reset = [4 => '    ports: !reset []'];

        $this->assertSame(
            ["{$local}:3: the service balancer does not reset its ports"],
            $this->portProblemsOf($local, $balancer + $mailer),
        );
        $this->assertSame([], $this->portProblemsOf($local, $balancer + $reset + $mailer));
        $this->assertSame(
            ["{$local}:14: only a reset of a service extending a part may say ports"],
            $this->portProblemsOf($local, $balancer + $reset + $mailer + [14 => "    ports: ['8025:8025']"]),
        );
    }

    /** A guard that stopped seeing the files would otherwise stay green over nothing. */
    public function testTheGuardSeesTheClusterStacks(): void
    {
        $this->assertGreaterThan(0, count($this->clusterStacks()), 'no cluster stack was found at all - the reader stopped seeing them');
    }

    /**
     * What the local file of one stack publishes on the host, one sentence per place.
     *
     * A service that extends a part must reset the ports that part publishes standing alone, and
     * no other `ports:` line may stand in the file.
     *
     * @param string $relativePath Repository-relative path of the file
     * @param array<int, string> $lines Line number => line
     * @return list<string> One sentence per place, each starting `path:number:`; empty when nothing is published
     */
    private function portProblemsOf(string $relativePath, array $lines): array
    {
        $problems = [];
        $resets = [];

        foreach ($this->servicesOf($lines) as $service => $body) {
            $extends = preg_grep(self::EXTENDS_PART, $body);
            if ($extends === []) {
                continue;
            }

            if (!in_array(self::PORTS_RESET, $body, true)) {
                $problems[] = "{$relativePath}:" . array_key_first($extends) . ": the service {$service} does not reset its ports";
            }
            $resets = array_merge($resets, array_keys($body, self::PORTS_RESET, true));
        }

        foreach (array_keys(preg_grep(self::PORTS_KEY, $lines)) as $number) {
            if (!in_array($number, $resets, true)) {
                $problems[] = "{$relativePath}:{$number}: only a reset of a service extending a part may say ports";
            }
        }

        return $problems;
    }

    /**
     * The cluster stacks of the repository and the files each one is judged by.
     *
     * @return array<string, array<string, string>> Demo name => repository-relative path => absolute path, the compose root first
     */
    private function clusterStacks(): array
    {
        $root = $this->repositoryRoot();
        $stacks = [];

        foreach (glob($root . '/demo/*/docker/docker-compose.cluster.yml') ?: [] as $composeRoot) {
            $docker = dirname($composeRoot);
            $paths = [$composeRoot];
            foreach (['/cluster/*.yml', '/cluster/*.env', '/cluster/nodes/*.env'] as $pattern) {
                $paths = array_merge($paths, glob($docker . $pattern) ?: []);
            }

            foreach ($paths as $path) {
                $stacks[basename(dirname($docker))][substr($path, strlen($root) + 1)] = $path;
            }
        }

        return $stacks;
    }

    /**
     * The lines of one file a person can read as its content: a commented-out line says nothing.
     *
     * @param string $path Absolute path of the file
     * @return array<int, string> Line number => line, without the comment lines
     */
    private function linesOf(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, "{$path} is unreadable");

        $read = [];
        foreach ($lines as $index => $line) {
            if (!str_starts_with(ltrim($line), '#')) {
                $read[$index + 1] = $line;
            }
        }

        return $read;
    }

    /**
     * Every line a stack is judged by, each labeled with where it stands.
     *
     * @param array<string, string> $files Repository-relative path => absolute path
     * @return iterable<string, string> `path:number` => line
     */
    private function linesOfStack(array $files): iterable
    {
        foreach ($files as $relativePath => $path) {
            foreach ($this->linesOf($path) as $number => $line) {
                yield "{$relativePath}:{$number}" => $line;
            }
        }
    }

    /**
     * The lines of one file that name something docker holds host-wide.
     *
     * A `name:` elsewhere is not one of them: the one inside `x-hilos-cluster` is a directory the
     * harness looks up inside a node. Inside `volumes` and `networks` a `name:` counts as a line of
     * its own and as a key of a one-line mapping such as `{driver: local, name: x}`.
     *
     * @param array<int, string> $lines Line number => line
     * @return array<int, string> The naming lines, keyed the same way
     */
    private function namingLinesOf(array $lines): array
    {
        $naming = [];
        $section = null;

        foreach ($lines as $number => $line) {
            if (preg_match(self::TOP_LEVEL_KEY, $line, $matches) === 1) {
                $section = $matches[1];
            }

            if (
                preg_match(self::PROJECT_NAME, $line) === 1
                || preg_match('/^\s*container_name:/', $line) === 1
                || (in_array($section, ['volumes', 'networks'], true) && preg_match('/(?:^\s+|[{,]\s*)name:/', $line) === 1)
            ) {
                $naming[$number] = $line;
            }
        }

        return $naming;
    }

    /**
     * The services of one compose file.
     *
     * A service starts at any line of the `services` section indented by exactly two spaces and
     * keyed, whatever follows the colon, and that line is the first line of its body.
     *
     * @param array<int, string> $lines Line number => line
     * @return array<string, array<int, string>> Service name => its lines, by line number
     */
    private function servicesOf(array $lines): array
    {
        $services = [];
        $section = null;
        $current = null;

        foreach ($lines as $number => $line) {
            if (preg_match(self::TOP_LEVEL_KEY, $line, $matches) === 1) {
                $section = $matches[1];
                $current = null;
                continue;
            }

            if ($section !== 'services') {
                continue;
            }

            if (preg_match('/^ {2}["\']?([A-Za-z0-9_.-]+)["\']?:/', $line, $matches) === 1) {
                $current = $matches[1];
                $services[$current] = [$number => $line];
                continue;
            }

            if ($current !== null) {
                $services[$current][$number] = $line;
            }
        }

        return $services;
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}

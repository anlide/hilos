<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-registry.php';

/**
 * Holds every service of a demo stand that mounts the project at /app to a data root of the
 * stand's own.
 *
 * A stand mounts its demo whole at /app, so whatever it does not cover writes into the data/ of
 * the local installation beside it. The export agents delete, at start and every hour, each file
 * of their directory that has no ready row in their own database (`expireCopies()` of
 * framework/backend/DataExport/AbstractDataExportAgent.php, `expire()` of
 * framework/backend/Legal/Export/LegalAcceptancesExports.php): after a test's db-reset that is
 * every archive of the local installation. So a service that mounts the project mounts
 * its stand's root at /app/data as well - `../data/test` on an ordinary stack, a named volume on
 * a cluster stand - and keeps every other mount under /app/data inside the stand's storage, which
 * on a cluster stand is its named volumes alone.
 *
 * The walk starts from the registry because a stand is born as a row of it, so a new demo meets
 * this rule the moment it gets one. The rows outside `demo/` run no demo's daemon. A cluster
 * stand is judged by its compose root and every `docker/cluster/*.yml` no `extends` of the stand
 * names; the named ones are parts, which a standalone deployment runs as they are.
 *
 * This is a check of the text of the files, not of the model compose resolves out of them. They
 * are read line by line because the repository has no YAML parser, the same way the other stand
 * guards read them. Into an `extends` the guard goes one level, and it merges the part's mounts
 * under the service's own by their target, as compose does. What it cannot read - an `extends` it
 * cannot follow, a volume line that is not `<source>:<target>`, a service whose value stands on
 * its own declaration line - is a refusal, not a pass: a service the guard cannot see may be the
 * one that leaks. An nginx of a stand reads the stand's files and does not mount the project, so
 * it is not judged.
 */
final class StandDataRootGuardTest extends TestCase
{
    /** The rule a message sends its reader to. */
    private const string RULES = 'docs/agents/testing.md';

    /** Where a service of a stand sees its data root. */
    private const string DATA_ROOT = '/app/data';

    /** Where a service that writes mounts the project. */
    private const string PROJECT_TARGET = '/app';

    /** The data root of an ordinary test stack, relative to its compose file. */
    private const string TEST_ROOT = '../data/test';

    /** The compose file of a cluster stand, relative to its demo. */
    private const string CLUSTER_COMPOSE = 'docker/docker-compose.cluster.yml';

    /** The files of a cluster stand beside its compose root, relative to its demo. */
    private const string CLUSTER_PARTS = 'docker/cluster/*.yml';

    /** Where a registry row of a demo starts. */
    private const string DEMO_ROOT = 'demo/';

    /** A key of a block mapping, and the value written after it on the same line; `<<` merges an anchor. */
    private const string KEY = '/^\s*["\']?([A-Za-z0-9_.-]+|<<)["\']?\s*:(?:\s+(.*))?$/';

    /** The key that merges a YAML anchor into a mapping. */
    private const string MERGE_KEY = '<<';

    /** A short-syntax volume line; a colon inside `${...}`, nested or not, does not separate. */
    private const string MOUNT = '~^\s*-\s+(?<q>["\']?)(?<source>(?:(?&var)|[^:$\s"\']|\$(?!\{))++)'
        . ':(?<target>(?:(?&var)|[^:$\s"\']|\$(?!\{))++)(?::[A-Za-z,]+)?\k<q>\s*(?:#.*)?$'
        . '(?(DEFINE)(?<var>\$\{(?:[^{}]|(?&var))*\}))~';

    /** A variable compose substitutes, `$NAME` or `${...}`: an odd run of `$`, since `$$` writes one `$` as it is. */
    private const string VARIABLE = '/(?<!\$)(?:\$\$)*\$(?!\$)/';

    /** The source of a mount that names a volume rather than a path. */
    private const string VOLUME_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';

    /** An empty list written after `volumes:`, which keeps the part's mounts. */
    private const string VOLUMES_EMPTY = '[]';

    /** The tags after `volumes:` that keep the part's mounts out. */
    private const array VOLUMES_RESETS = ['!override', '!reset', '!reset []'];

    /** A stand whose writer lost its root writes into the local installation's data. */
    public function testEveryDemoStandMountsItsOwnDataRoot(): void
    {
        $problems = array_values(array_filter($this->standVerdicts()));

        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
    }

    /** No root, the local root, a mount past the stand's root and a variable, braced or bare, each name the service; `$$` is none. */
    public function testAStandWithoutItsRootIsNamed(): void
    {
        $compose = 'demo/shop/docker/docker-compose.test.yml';
        $verdicts = $this->verdictsOf($compose, [
            'services:',
            '  shop-nginx-test:',
            '    volumes:',
            '      - ../data/status:/status:ro',
            '  shop-daemon-test:',
            '    volumes:',
            '      - ..:/app',
            '      - ../data/logs-test:/var/log/hilos',
            '  shop-cli-test:',
            '    volumes:',
            '      - ..:/app',
            '      - ../data:/app/data',
            '  shop-worker-test:',
            '    volumes:',
            '      - ..:/app',
            '      # The stand\'s own root.',
            '      - ../data/test:/app/data',
            '      - ../data/data_export:/app/data/data_export',
            '  shop-export-test:',
            '    volumes:',
            '      - "..:/app"',
            '      - ../data/test:/app/data',
            '      - ${SHOP_EXPORT_DIR:-../data/test/data_export}:/app/data/data_export:ro',
            '  shop-files-test:',
            '    volumes:',
            '      - ..:/app',
            '      - ../data/test:/app/data',
            '      - ../data/test/$SUB:/app/data/files',
            '  shop-import-test:',
            '    volumes:',
            '      - ..:/app',
            '      - ../data/test:/app/data',
            '      - ../data/test/files:/app/data/$DIR',
            '  shop-mailer-test:',
            '    volumes:',
            '    - ..:/app',
            '    - ../data/test:/app/data',
            '    - ../data/test/mail:/app/data/mail',
            '    - ../data/test/a$$b:/app/data/x',
            '    - mail-queue:/app/data/queue',
            '    tmpfs:',
            '      - /app/data/tmp',
        ], [], false);

        $this->assertSame(
            [
                'shop-daemon-test',
                'shop-cli-test',
                'shop-worker-test',
                'shop-export-test',
                'shop-files-test',
                'shop-import-test',
                'shop-mailer-test',
            ],
            array_keys($verdicts),
        );
        $this->assertNull($verdicts['shop-mailer-test']);

        $named = [
            'shop-daemon-test' => ["{$compose}:5:", 'it mounts nothing at /app/data'],
            'shop-cli-test' => ["{$compose}:9:", 'its data root `../data:/app/data` is not `../data/test`'],
            'shop-worker-test' => ["{$compose}:13:", '`../data/data_export:/app/data/data_export` lies outside the stand\'s storage'],
            'shop-export-test' => [
                "{$compose}:19:",
                '`${SHOP_EXPORT_DIR:-../data/test/data_export}:/app/data/data_export` reads its source from a variable',
            ],
            'shop-files-test' => ["{$compose}:24:", '`../data/test/$SUB:/app/data/files` reads its source from a variable'],
            'shop-import-test' => ["{$compose}:29:", '`../data/test/files:/app/data/$DIR` reads its target from a variable'],
        ];
        foreach ($named as $service => $expected) {
            $message = $verdicts[$service];
            $this->assertIsString($message);
            $this->assertStringStartsWith("{$expected[0]} the service {$service} mounts the project at /app but ", $message);
            $this->assertStringContainsString($expected[1], $message);
            foreach (['mount `../data/test:/app/data`', 'its own named data volume', 'docs/agents/testing.md'] as $advice) {
                $this->assertStringContainsString($advice, $message);
            }
        }
    }

    /** A node takes the project from its part, so its root is judged under what it inherits; its storage is named volumes alone. */
    public function testAClusterServiceInheritsTheProjectFromItsPart(): void
    {
        $local = 'demo/shop/docker/cluster/local.yml';
        $parts = ['demo/shop/docker/cluster/node.yml' => [
            'services:',
            '  node:',
            '    command: ["php", "backend/Bootstrap/docker.php"]',
            '    volumes:',
            '      - ../..:/app',
            '      - ${SHOP_CLUSTER_DATA_EXPORT_DIR:-../../data/data_export}:/app/data/data_export',
            '      - ${SHOP_CLUSTER_LOG_DIR:-../../data/logs-cluster/node}:/var/log/hilos',
        ]];
        $verdicts = $this->verdictsOf($local, [
            'services:',
            '  shop-cluster-m1:',
            '    extends: {file: ./node.yml, service: node}',
            '    volumes:',
            '      - ../../data/logs-cluster/m1:/var/log/hilos',
            '  shop-cluster-m2:',
            '    extends: {file: ./node.yml, service: node}',
            '    volumes:',
            '      - data:/app/data',
            '      - data-export:/app/data/data_export',
            '  shop-cluster-m3:',
            '    extends: {file: ./node.yml, service: node}',
            '    volumes:',
            '      - data:/app/data',
            '  shop-cluster-m4:',
            '    extends: {file: ./node.yml, service: node}',
            '    volumes:',
            '      - ../../data:/app/data',
            '      - data-export:/app/data/data_export',
            '  shop-cluster-m5:',
            '    extends: {file: ./node.yml, service: node}',
            '    volumes:',
            '      - data:/app/data',
            '      - data-export:/app/data/data_export',
            '      - ../data/test/x:/app/data/x',
            '  shop-cluster-probe:',
            '    extends: {file: ./node.yml, service: node}',
            '    volumes: !override',
            '      - data:/app/data/probe',
            'volumes:',
            '  data:',
            '    name: hilos-shop-cluster-data${HILOS_STAND_SUFFIX:-}',
        ], $parts, true);

        $this->assertSame(['shop-cluster-m1', 'shop-cluster-m2', 'shop-cluster-m3', 'shop-cluster-m4', 'shop-cluster-m5'], array_keys($verdicts));
        $this->assertNull($verdicts['shop-cluster-m2']);

        $named = [
            'shop-cluster-m1' => ["{$local}:2:", 'it mounts nothing at /app/data'],
            'shop-cluster-m3' => [
                "{$local}:11:",
                '`${SHOP_CLUSTER_DATA_EXPORT_DIR:-../../data/data_export}:/app/data/data_export` of its part '
                    . 'demo/shop/docker/cluster/node.yml reads its source from a variable',
            ],
            'shop-cluster-m4' => ["{$local}:15:", 'its data root `../../data:/app/data` is not a named volume'],
            'shop-cluster-m5' => ["{$local}:20:", '`../data/test/x:/app/data/x` lies outside the stand\'s storage'],
        ];
        foreach ($named as $service => $expected) {
            $message = $verdicts[$service];
            $this->assertIsString($message);
            $this->assertStringStartsWith("{$expected[0]} the service {$service} mounts the project at /app but ", $message);
            $this->assertStringContainsString($expected[1], $message);
        }
    }

    /** An `extends` the guard cannot follow, a volume line it cannot split, or a value beside a service's name hides what it must judge. */
    public function testWhatTheGuardCannotReadIsRefused(): void
    {
        $local = 'demo/shop/docker/cluster/local.yml';
        $parts = [
            'demo/shop/docker/cluster/node.yml' => [
                'services:',
                '  node:',
                '    volumes:',
                '      - ../..:/app',
                '  relay:',
                '    extends: {file: ./base.yml, service: base}',
                '  bound:',
                '    volumes:',
                '      - type: bind',
                '        source: ../..',
                '        target: /app',
                '  aliased: *node',
            ],
        ];
        $unread = [
            '    extends: node' => 'its `extends: node` names no file',
            '    extends: {service: node}' => 'its `extends: {service: node}` names no file',
            '    extends: {file: ./node.yml, service: node, profile: x}' => 'is not of the form `{file: …, service: …}`',
            '    extends:' => 'its `extends` spans several lines',
            '    extends: {file: ./gone.yml, service: node}' => 'its part demo/shop/docker/cluster/gone.yml is not found',
            '    extends: {file: ./node.yml, service: gone}' => 'its part demo/shop/docker/cluster/node.yml has no service gone',
            '    extends: {file: ./node.yml, service: relay}' => 'the service relay of its part demo/shop/docker/cluster/node.yml extends',
            '    extends: {file: ./node.yml, service: bound}' => 'the volume line demo/shop/docker/cluster/node.yml:9 `- type: bind`',
            '    extends: {file: ./node.yml, service: aliased}'
                => 'the service aliased of its part demo/shop/docker/cluster/node.yml writes its value `*node` on its declaration line',
            '    <<: *node' => 'it merges a YAML anchor',
            '    volumes: [data:/app/data]' => '`volumes: [data:/app/data]` is not a list of lines',
            '    volumes:' => "the volume line {$local}:4 `- \${UNCLOSED:/app/data`",
            '  shop-cluster-m1: *node' => 'it writes its value `*node` on its declaration line',
            "  shop-cluster-m1: {volumes: ['..:/app']}" => "it writes its value `{volumes: ['..:/app']}` on its declaration line",
        ];

        foreach ($unread as $line => $reason) {
            $lines = str_starts_with($line, '  shop-cluster-m1:') ? ['services:', $line] : ['services:', '  shop-cluster-m1:', $line];
            if ($line === '    extends:') {
                $lines = array_merge($lines, ['      file: ./node.yml', '      service: node']);
            } elseif ($line === '    volumes:') {
                $lines[] = '      - ${UNCLOSED:/app/data';
            }

            $message = $this->verdictsOf($local, $lines, $parts, true)['shop-cluster-m1'] ?? null;
            $this->assertIsString($message, "no refusal for `{$line}`");
            $this->assertStringStartsWith("{$local}:2: the guard cannot read the service shop-cluster-m1: ", $message);
            $this->assertStringContainsString($reason, $message);
            $this->assertStringContainsString('docs/agents/testing.md', $message);
        }
    }

    /** A guard that stopped seeing the stands, or their writers, would otherwise stay green over nothing. */
    public function testTheGuardSeesTheDemoStands(): void
    {
        $this->assertGreaterThan(0, count($this->demoStands()), 'no demo stand was found at all - the reader stopped seeing them');
        $this->assertGreaterThan(0, count($this->standVerdicts()), 'no service mounting the project was found - the reader stopped seeing them');
    }

    /**
     * The verdict on every service of the demo stands that mounts the project or that the guard
     * cannot read.
     *
     * @return list<?string> One sentence per service that fails the rule, null per service that holds it
     */
    private function standVerdicts(): array
    {
        $verdicts = [];
        foreach ($this->demoStands() as $stand) {
            $files = $this->filesOf($stand);
            $parts = [];
            foreach ($files as $path => $lines) {
                foreach ($this->servicesOf($lines) as $body) {
                    $part = $this->extendsOf($path, $this->entriesOf(array_slice($body, 1, null, true)));
                    if (is_array($part)) {
                        $parts[$part['part']] = true;
                    }
                }
            }

            foreach (array_keys($parts) as $part) {
                if (!isset($files[$part]) && is_file($this->repositoryRoot() . '/' . $part)) {
                    $files[$part] = $this->linesOf($part);
                }
            }

            $cluster = $stand['composeFile'] === self::CLUSTER_COMPOSE;
            foreach (array_diff_key($files, $parts) as $path => $lines) {
                $verdicts = array_merge($verdicts, array_values($this->verdictsOf($path, $lines, $files, $cluster)));
            }
        }

        return $verdicts;
    }

    /**
     * What every service of one file that mounts the project, or that the guard cannot read, does
     * with its data root.
     *
     * @param string $path Repository-relative path of the file
     * @param array<int, string> $lines The lines of that file
     * @param array<string, array<int, string>> $files Repository-relative path => lines, of every file an `extends` may name
     * @param bool $cluster Whether the file belongs to a cluster stand, whose storage is its named volumes
     * @return array<string, ?string> Service name => the sentence for whoever wrote it, or null when it holds the rule
     */
    private function verdictsOf(string $path, array $lines, array $files, bool $cluster): array
    {
        $verdicts = [];
        foreach ($this->servicesOf($lines) as $service => $body) {
            $place = $path . ':' . array_key_first($body);
            $value = $this->valueOf($body);
            $mounts = $value === null
                ? $this->mountsOfService($path, $this->entriesOf(array_slice($body, 1, null, true)), $files)
                : "it writes its value `{$value}` on its declaration line";

            if (is_string($mounts)) {
                $verdicts[$service] = "{$place}: the guard cannot read the service {$service}: {$mounts}; a service that may mount the "
                    . 'project at ' . self::PROJECT_TARGET . ' writes its keys in a block below its name, keeps its `extends` on one '
                    . 'line as `{file: …, service: …}`, one level deep, and its volumes as `- <source>:<target>` lines; see ' . self::RULES;
            } elseif (isset($mounts[self::PROJECT_TARGET])) {
                $wrong = $this->wrongMountsOf($mounts, $cluster);
                $verdicts[$service] = $wrong === []
                    ? null
                    : "{$place}: the service {$service} mounts the project at " . self::PROJECT_TARGET . ' but '
                        . implode(', and ', $wrong) . '; mount `' . self::TEST_ROOT . ':' . self::DATA_ROOT
                        . '` — a cluster stand: its own named data volume — and keep every other mount under ' . self::DATA_ROOT
                        . ' inside it; see ' . self::RULES;
            }
        }

        return $verdicts;
    }

    /**
     * What one service's mounts break of the rule.
     *
     * @param array<string, array{source: string, target: string, part?: string}> $mounts Target => mount, the project among them
     * @param bool $cluster Whether the service belongs to a cluster stand
     * @return list<string> One clause per broken requirement, empty when the service holds the rule
     */
    private function wrongMountsOf(array $mounts, bool $cluster): array
    {
        $wrong = isset($mounts[self::DATA_ROOT]) ? [] : ['it mounts nothing at ' . self::DATA_ROOT];
        foreach ($mounts as $target => $mount) {
            $shown = "`{$mount['source']}:{$target}`";
            if (isset($mount['part'])) {
                $shown .= " of its part {$mount['part']}";
            }

            if (preg_match(self::VARIABLE, $target) === 1) {
                $wrong[] = "{$shown} reads its target from a variable";
            } elseif ($target !== self::DATA_ROOT && !str_starts_with($target, self::DATA_ROOT . '/')) {
                continue;
            } elseif (preg_match(self::VARIABLE, $mount['source']) === 1) {
                $wrong[] = "{$shown} reads its source from a variable";
            } elseif ($target === self::DATA_ROOT && $cluster && preg_match(self::VOLUME_NAME, $mount['source']) !== 1) {
                $wrong[] = "its data root {$shown} is not a named volume";
            } elseif ($target === self::DATA_ROOT && !$cluster && $mount['source'] !== self::TEST_ROOT) {
                $wrong[] = "its data root {$shown} is not `" . self::TEST_ROOT . '`';
            } elseif ($target !== self::DATA_ROOT && !$this->isInsideStandStorage($mount['source'], $cluster)) {
                $wrong[] = "{$shown} lies outside the stand's storage";
            }
        }

        return $wrong;
    }

    /**
     * The mounts one service ends up with: its part's, then its own over them by target.
     *
     * @param string $path Repository-relative path of the file that declares the service
     * @param array<string, array<int, string>> $keys The keys of the service, as {@see entriesOf()} gives them
     * @param array<string, array<int, string>> $files Repository-relative path => lines, of every file an `extends` may name
     * @return array<string, array{source: string, target: string, part?: string}>|string Target => mount, or why the guard cannot read them
     */
    private function mountsOfService(string $path, array $keys, array $files): array|string
    {
        if (isset($keys[self::MERGE_KEY])) {
            return 'it merges a YAML anchor, which the guard does not follow';
        }

        $part = $this->extendsOf($path, $keys);
        if (is_string($part)) {
            return $part;
        }

        $own = $this->mountsOf($path, $keys);
        if (is_string($own) || $part === null || in_array($this->volumesValueOf($keys), self::VOLUMES_RESETS, true)) {
            return $own;
        }

        if (!isset($files[$part['part']])) {
            return "its part {$part['part']} is not found";
        }

        $services = $this->servicesOf($files[$part['part']]);
        if (!isset($services[$part['service']])) {
            return "its part {$part['part']} has no service {$part['service']}";
        }

        $partValue = $this->valueOf($services[$part['service']]);
        if ($partValue !== null) {
            return "the service {$part['service']} of its part {$part['part']} writes its value `{$partValue}` on its declaration line";
        }

        $partKeys = $this->entriesOf(array_slice($services[$part['service']], 1, null, true));
        if (isset($partKeys['extends']) || isset($partKeys[self::MERGE_KEY])) {
            return "the service {$part['service']} of its part {$part['part']} extends another in turn, past the one level the guard follows";
        }

        $inherited = $this->mountsOf($part['part'], $partKeys);
        if (is_string($inherited)) {
            return $inherited;
        }

        return $own + array_map(static fn(array $mount): array => $mount + ['part' => $part['part']], $inherited);
    }

    /**
     * The part one service extends.
     *
     * @param string $path Repository-relative path of the file that declares the service
     * @param array<string, array<int, string>> $keys The keys of the service, as {@see entriesOf()} gives them
     * @return array{part: string, service: string}|string|null The part's repository-relative path and service, why the guard
     *     cannot follow the `extends`, or null when the service extends nothing
     */
    private function extendsOf(string $path, array $keys): array|string|null
    {
        if (!isset($keys['extends'])) {
            return null;
        }

        $value = $this->valueOf($keys['extends']);
        if ($value === null) {
            return 'its `extends` spans several lines, which the guard does not read';
        }

        if (preg_match('/^\{(.*)\}$/', $value, $matches) !== 1) {
            return "its `extends: {$value}` names no file";
        }

        $fields = [];
        foreach (explode(',', $matches[1]) as $pair) {
            if (preg_match('/^\s*(\w+)\s*:\s*["\']?([^"\'\s]+)["\']?\s*$/', $pair, $field) !== 1) {
                return "its `extends: {$value}` is not of the form `{file: …, service: …}`";
            }
            $fields[$field[1]] = $field[2];
        }

        if (!isset($fields['file'])) {
            return "its `extends: {$value}` names no file";
        }

        if (!isset($fields['service']) || count($fields) !== 2) {
            return "its `extends: {$value}` is not of the form `{file: …, service: …}`";
        }

        return ['part' => $this->normalized(dirname($path) . '/' . $fields['file']), 'service' => $fields['service']];
    }

    /**
     * The mounts one service writes itself.
     *
     * @param string $path Repository-relative path of the file that declares the service
     * @param array<string, array<int, string>> $keys The keys of the service, as {@see entriesOf()} gives them
     * @return array<string, array{source: string, target: string}>|string Target => mount, or why the guard cannot read them
     */
    private function mountsOf(string $path, array $keys): array|string
    {
        if (!isset($keys['volumes'])) {
            return [];
        }

        $value = $this->volumesValueOf($keys);
        if ($value !== null && $value !== self::VOLUMES_EMPTY && !in_array($value, self::VOLUMES_RESETS, true)) {
            return "its `volumes: {$value}` is not a list of lines the guard reads";
        }

        $mounts = [];
        foreach (array_slice($keys['volumes'], 1, null, true) as $number => $line) {
            if (preg_match(self::MOUNT, $line, $matches) !== 1) {
                return "the volume line {$path}:{$number} `" . trim($line) . '` is not `<source>:<target>`';
            }

            $target = rtrim($matches['target'], '/');
            $mounts[$target] = ['source' => rtrim($matches['source'], '/'), 'target' => $target];
        }

        return $mounts;
    }

    /**
     * What one service writes after its `volumes:` key, on the same line.
     *
     * @param array<string, array<int, string>> $keys The keys of the service, as {@see entriesOf()} gives them
     * @return ?string The value, or null when the service has no `volumes:` or writes its list below it
     */
    private function volumesValueOf(array $keys): ?string
    {
        return isset($keys['volumes']) ? $this->valueOf($keys['volumes']) : null;
    }

    /**
     * What a key writes after itself on its own line, without a trailing comment.
     *
     * @param array<int, string> $entry The key's lines, its own line first, as {@see entriesOf()} gives them
     * @return ?string The value, or null when the key writes nothing after itself and opens a block
     */
    private function valueOf(array $entry): ?string
    {
        if (preg_match(self::KEY, $entry[array_key_first($entry)], $matches) !== 1 || !isset($matches[2])) {
            return null;
        }

        $value = trim(preg_replace('/(?:^|\s+)#.*$/', '', $matches[2]));

        return $value === '' ? null : $value;
    }

    /**
     * Whether a mount source is the stand's own: a named volume, or on an ordinary test stack a
     * path under its data root as well.
     *
     * A cluster stand keeps its storage in its named volumes alone: they carry the instance's
     * suffix through the compose project, while a path is one directory for every instance.
     *
     * @param string $source The source of a mount, relative to its compose file
     * @param bool $cluster Whether the mount belongs to a cluster stand
     * @return bool True when the source names a volume, or on an ordinary stack stays under `../data/test/`
     */
    private function isInsideStandStorage(string $source, bool $cluster): bool
    {
        return preg_match(self::VOLUME_NAME, $source) === 1
            || (!$cluster
                && str_starts_with($source, self::TEST_ROOT . '/')
                && !in_array('..', explode('/', substr($source, strlen(self::TEST_ROOT) + 1)), true));
    }

    /**
     * A repository-relative path with its `.` and `..` segments resolved.
     *
     * @param string $path Repository-relative path, as a part's directory joined with its `file:`
     * @return string The same path, resolved
     */
    private function normalized(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * The services of one compose file.
     *
     * @param array<int, string> $lines The lines of the file
     * @return array<string, array<int, string>> Service name => its lines by line number from 1, its declaration first
     */
    private function servicesOf(array $lines): array
    {
        $numbered = [];
        foreach ($lines as $index => $line) {
            $numbered[$index + 1] = $line;
        }

        $section = $this->entriesOf($numbered)['services'] ?? [];

        return $this->entriesOf(array_slice($section, 1, null, true));
    }

    /**
     * The entries of one block mapping, read by indentation.
     *
     * The first line read sets the block's own indent, and each line at that indent that names a
     * key opens an entry. A line at that indent that names no key - a sequence item written flush
     * with its key - stays with the entry above it, as YAML reads it. Blank and comment lines are
     * not read.
     *
     * @param array<int, string> $lines Line number => line, the block's lines only
     * @return array<string, array<int, string>> Key => its lines by line number, the key's own line first
     */
    private function entriesOf(array $lines): array
    {
        $entries = [];
        $indent = null;
        $current = null;

        foreach ($lines as $number => $line) {
            $content = ltrim($line);
            if ($content === '' || str_starts_with($content, '#')) {
                continue;
            }

            $indent ??= strlen($line) - strlen($content);
            if (strlen($line) - strlen($content) === $indent && preg_match(self::KEY, $line, $matches) === 1) {
                $current = $matches[1];
                $entries[$current] = [];
            }

            if ($current !== null) {
                $entries[$current][$number] = $line;
            }
        }

        return $entries;
    }

    /**
     * The files one stand is judged by: its compose file, or a cluster stand's compose root and
     * every file beside its parts.
     *
     * @param array{cwd: string, composeFile: string} $stand Registry row of the stand
     * @return array<string, array<int, string>> Repository-relative path => lines
     */
    private function filesOf(array $stand): array
    {
        $files = [$stand['cwd'] . '/' . $stand['composeFile'] => []];
        if ($stand['composeFile'] === self::CLUSTER_COMPOSE) {
            $root = $this->repositoryRoot();
            foreach (glob("{$root}/{$stand['cwd']}/" . self::CLUSTER_PARTS) ?: [] as $part) {
                $files[substr($part, strlen($root) + 1)] = [];
            }
        }

        foreach (array_keys($files) as $path) {
            $files[$path] = $this->linesOf($path);
        }

        return $files;
    }

    /**
     * The registry rows of the demos' stands.
     *
     * @return array<int, array{cwd: string, composeFile: string}>
     */
    private function demoStands(): array
    {
        return array_filter(
            standRegistry($this->repositoryRoot()),
            static fn(array $stand): bool => str_starts_with($stand['cwd'], self::DEMO_ROOT),
        );
    }

    /**
     * The lines of one file of the repository.
     *
     * @param string $path Repository-relative path
     * @return array<int, string>
     */
    private function linesOf(string $path): array
    {
        $lines = file($this->repositoryRoot() . '/' . $path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines, "{$path} is unreadable");

        return $lines;
    }

    /**
     * @return string Absolute path of the repository root
     */
    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}

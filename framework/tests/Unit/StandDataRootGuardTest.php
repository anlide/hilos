<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Tests\Fixtures\StandComposeReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-registry.php';

/**
 * Holds demo stands to their own data roots, and every registry stand to clone ownership declarations.
 *
 * A demo mounted at /app needs an isolated /app/data: after db-reset its export agents may delete
 * every archive without a ready row. Ordinary stands bind data/test; cluster stands use named
 * volumes. The existing isolation pass covers every demo registry row and cluster aggregate.
 *
 * The ownership pass also covers framework/frontend tools, restricted to the effective test profile
 * in a shared compose file. Every demo needs its data/.gitignore parent. A writable clone bind needs
 * the common writer entrypoint, a writable /app or /hilos owner root and explicit output directories.
 * Read-only clone readers, including root PHPUnit with container-local storage, need no writer.
 * Runtime tests, rather than this text guard, prove the process identity and repair of old outputs.
 *
 * The small reader follows one level of extends, including same-file references, and declared
 * same-file service anchors. Extends merges mounts by target and environment by name; YAML merge
 * replaces whole fields locally. Cluster parts are judged through their effective test services,
 * not as standalone deployments. Unknown service or mount forms refuse with their file and service.
 */
final class StandDataRootGuardTest extends TestCase
{
    use StandComposeReader;

    /** The rule a message sends its reader to. */
    private const string RULES = 'docs/agents/testing/stand-compose.md';

    /** Where a service of a stand sees its data root. */
    private const string DATA_ROOT = '/app/data';

    /** Where a service that writes mounts the project. */
    private const string PROJECT_TARGET = '/app';

    /** The data root of an ordinary test stack, relative to its compose file. */
    private const string TEST_ROOT = '../data/test';

    /** Where a registry row of a demo starts. */
    private const string DEMO_ROOT = 'demo/';

    /** A short-syntax volume line; a colon inside `${...}`, nested or not, does not separate. */
    private const string MOUNT = '~^\s*-\s+(?<q>["\']?)(?<source>(?:(?&var)|[^:$\s"\']|\$(?!\{))++)'
        . ':(?<target>(?:(?&var)|[^:$\s"\']|\$(?!\{))++)(?::(?<mode>[A-Za-z,]+))?\k<q>\s*(?:#.*)?$'
        . '(?(DEFINE)(?<var>\$\{(?:[^{}]|(?&var))*\}))~';

    /** A variable compose substitutes, `$NAME` or `${...}`: an odd run of `$`, since `$$` writes one `$` as it is. */
    private const string VARIABLE = '/(?<!\$)(?:\$\$)*\$(?!\$)/';

    /** The source of a mount that names a volume rather than a path. */
    private const string VOLUME_NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';

    /** An empty list written after `volumes:`, which keeps the part's mounts. */
    private const string VOLUMES_EMPTY = '[]';

    /** The tags after `volumes:` that keep the part's mounts out. */
    private const array VOLUMES_RESETS = ['!override', '!reset', '!reset []'];

    /** The shared test writer, reached through the framework's read-only mount too. */
    private const string WRITER = '/hilos/framework/docker/test-writer-entrypoint.sh';

    /** The bind root whose stat supplies the test process identity. */
    private const string OWNER_ROOT = 'HILOS_TEST_OWNER_ROOT';

    /** The explicit directories the test writer prepares before dropping root. */
    private const string WRITE_DIRS = 'HILOS_TEST_WRITE_DIRS';

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
            foreach (['mount `../data/test:/app/data`', 'its own named data volume', 'docs/agents/testing/stand-compose.md'] as $advice) {
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

    /** The browser overlay replaces a node mount or its writer while its project bind survives. */
    public function testBrowserOverridesAreJudgedOnTheInheritedNode(): void
    {
        $docker = 'demo/ecommerce-shop/docker';
        $browser = "{$docker}/docker-compose.cluster-browser.yml";
        $overlay = "{$docker}/cluster/browser.yml";
        $scenario = "{$docker}/docker-compose.cluster.yml";
        foreach ([
            ['    volumes:', '      - ../../data:/app/data', 'named volume'],
            ['    entrypoint: []', 'entrypoint'],
        ] as $override) {
            $reason = array_pop($override);
            $lines = $this->linesOf($overlay);
            $node = 'ecommerce-shop-cluster-m1';
            $position = array_search("  {$node}:", $lines, true);
            $this->assertIsInt($position);
            array_splice($lines, $position + 1, 0, $override);
            $files = [$overlay => $lines];
            $this->assertNull($this->verdictsOf($scenario, $this->linesOf($scenario), $files, true, true)[$node]);
            $message = $this->verdictsOf($browser, $this->linesOf($browser), $files, true, true)[$node];
            $this->assertIsString($message);
            $this->assertStringContainsString($reason, $message);
        }
    }

    /** A seed that supplies its own writer cannot cover a broken exported CLI. */
    public function testAnExtendedToolIsAlsoJudgedAsAService(): void
    {
        $path = 'demo/shop/docker/compose.yml';
        $files = [
            'demo/shop/docker/cluster/tooling.yml' => [
                'services:',
                '  cli:',
                '    volumes:',
                '      - ../..:/app',
                '      - data:/app/data',
            ],
            'demo/shop/docker/cluster/browser.yml' => [
                'services:',
                '  seed:',
                '    extends: {file: ./tooling.yml, service: cli}',
                '    entrypoint: [sh, /hilos/framework/docker/test-writer-entrypoint.sh]',
                '    environment:',
                '      HILOS_TEST_OWNER_ROOT: /app',
                '      HILOS_TEST_WRITE_DIRS: /app/data',
            ],
        ];
        $verdicts = $this->verdictsOf($path, ['include:', '  - path: [./cluster/tooling.yml, ./cluster/browser.yml]'], $files, true, true);
        $this->assertIsString($verdicts['cli']);
        $this->assertStringContainsString('entrypoint', $verdicts['cli']);
        $this->assertNull($verdicts['seed']);
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
            '    extends: {service: node}' => "its part {$local} has no service node",
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
            $this->assertStringContainsString('docs/agents/testing/stand-compose.md', $message);
        }
    }

    /** A guard that stopped seeing the stands, or their writers, would otherwise stay green over nothing. */
    public function testTheGuardSeesTheDemoStands(): void
    {
        $this->assertGreaterThan(0, count($this->demoStands()), 'no demo stand was found at all - the reader stopped seeing them');
        $this->assertGreaterThan(0, count($this->standVerdicts()), 'no service mounting the project was found - the reader stopped seeing them');
    }

    /** All registry rows enter ownership coverage, including the two shared tool stands and twelve demo projects. */
    public function testOwnershipCoversTheRegistryAndDemoDataMarkers(): void
    {
        $problems = array_values(array_filter($this->standVerdicts(true)));
        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
        $this->assertCount(13, array_unique(array_column($this->demoStands(), 'cwd')));

        foreach (['framework' => 'hilos-deps-test', 'frontend' => 'hilos-frontend-cli'] as $id => $writer) {
            $stand = standById($this->repositoryRoot(), $id);
            $this->assertNotNull($stand);
            $path = $stand['composeFile'];
            $verdicts = $this->verdictsOf($path, $this->linesOf($path), [], false, true, $stand['profiles']);
            $this->assertArrayHasKey($writer, $verdicts);
            $this->assertNull($verdicts[$writer]);
            if ($id === 'framework') {
                foreach (['hilos-cli-test', 'mysql-framework-remote-test', 'mysql-framework-catalog-test',
                    'mysql-framework-restore-test'] as $reader) {
                    $this->assertArrayHasKey($reader, $verdicts);
                    $this->assertNull($verdicts[$reader]);
                }
                $this->assertArrayNotHasKey('ollama-local', $verdicts);
                $this->assertArrayNotHasKey('hilos-preview-caddy', $verdicts);
            }
        }
    }

    /** Missing parents name the demo's marker, rather than relying on Docker to create a root-owned data directory. */
    public function testAMissingDataMarkerNamesItsDemo(): void
    {
        $this->assertNull($this->markerVerdictOf('demo/shop', true));
        $this->assertSame(
            'demo/shop/data/.gitignore: a demo with a test stand needs its data/ parent in Git; see docs/agents/testing/stand-compose.md',
            $this->markerVerdictOf('demo/shop', false),
        );
    }

    /** Every writable clone bind needs all three declarations; empty, variable and unsafe outputs are refusals too. */
    public function testAnUndeclaredCloneWriterIsNamed(): void
    {
        $path = 'framework/docker/writer.test.yml';
        $lines = $this->writerFixture();
        foreach ([
            '    entrypoint:' => 'entrypoint',
            '      HILOS_TEST_OWNER_ROOT:' => self::OWNER_ROOT,
            '      HILOS_TEST_WRITE_DIRS:' => self::WRITE_DIRS,
        ] as $prefix => $reason) {
            $changed = array_values(array_filter($lines, static fn(string $line): bool => !str_starts_with($line, $prefix)));
            $message = $this->verdictsOf($path, $changed, [], false, true)['writer'];
            $this->assertIsString($message);
            $this->assertStringStartsWith("{$path}:2: the service writer:", $message);
            $this->assertStringContainsString($reason, $message);
            $this->assertStringContainsString(self::RULES, $message);
        }
        foreach (['', '""', '${OUTPUT}', '/hilos', '/hilos/data', '/hilos/../outside', 'vendor'] as $dirs) {
            $changed = array_map(
                static fn(string $line): string => str_starts_with($line, '      HILOS_TEST_WRITE_DIRS:')
                    ? '      HILOS_TEST_WRITE_DIRS: ' . $dirs : $line,
                $lines,
            );
            $message = $this->verdictsOf($path, $changed, [], false, true)['writer'];
            $this->assertIsString($message, "accepted outputs `{$dirs}`");
            $this->assertStringContainsString(self::WRITE_DIRS, $message);
        }
    }

    /** Read-only configuration readers and the root PHPUnit runner need no identity switch or writable clone outputs. */
    public function testReadersAndRootReadOnlyPhpunitAreAllowed(): void
    {
        $verdicts = $this->verdictsOf('framework/docker/readers.test.yml', [
            'services:',
            '  phpunit:',
            '    user: root',
            '    volumes:',
            '      - ../..:/hilos:ro',
            '      - cache:/tmp/phpunit',
            '      - ${TEST_DB_DIR:-/dev/shm/test-database}:/var/lib/mysql',
            '  nginx:',
            '    volumes:',
            '      - ./nginx.conf:/etc/nginx/nginx.conf:ro',
        ], [], false, true);
        $this->assertSame(['phpunit' => null, 'nginx' => null], $verdicts);
    }

    /** A writable child is still a clone writer, even when the root bind is read-only and writer fields are present. */
    public function testAReadOnlyRootDoesNotLicenseAWritableChild(): void
    {
        $lines = array_map(static fn(string $line): string => str_replace('../..:/hilos:rw', '../..:/hilos:ro', $line),
            $this->writerFixture());
        $lines[] = '      - ../../vendor:/hilos/vendor:rw';
        $message = $this->verdictsOf('framework/docker/child.test.yml', $lines, [], false, true)['writer'];
        $this->assertIsString($message);
        $this->assertStringContainsString(self::OWNER_ROOT, $message);
        $this->assertStringContainsString('read-only root does not cover child rw binds', $message);
    }

    /** Effective inheritance supplies writers, local fields override them, and only declared, one-level anchors are read. */
    public function testInheritedWritersLocalOverridesAndDatabaseAnchorsAreRead(): void
    {
        $path = 'framework/docker/inherited.test.yml';
        $part = 'framework/docker/part.yml';
        $files = [$part => $this->writerFixture()];
        $local = ['services:', '  local:', '    extends: {file: ./part.yml, service: writer}'];
        $this->assertSame(['local' => null], $this->verdictsOf($path, $local, $files, false, true));
        foreach ([
            ['    entrypoint: []'],
            ['    environment:', '      HILOS_TEST_OWNER_ROOT: /app'],
            ['    environment:', '      HILOS_TEST_WRITE_DIRS: ""'],
            ['    environment: {HILOS_TEST_OWNER_ROOT: /hilos}'],
        ] as $override) {
            $message = $this->verdictsOf($path, array_merge($local, $override), $files, false, true)['local'];
            $this->assertIsString($message);
            $this->assertStringContainsString("{$path}:2: the service local:", $message);
            $this->assertStringContainsString(self::RULES, $message);
        }
        $folded = array_merge($local, [
            '    environment:',
            '      HILOS_TEST_WRITE_DIRS: >-',
            '        /hilos/vendor',
            '        /tmp/cache',
        ]);
        $this->assertSame(['local' => null], $this->verdictsOf($path, $folded, $files, false, true));
        $listed = array_merge($local, ['    environment:', '      - HILOS_TEST_WRITE_DIRS=/hilos/vendor']);
        $this->assertSame(['local' => null], $this->verdictsOf($path, $listed, $files, false, true));
        $reader = array_merge($local, ['    entrypoint: []', '    volumes:', '      - ../..:/hilos:ro']);
        $this->assertSame(['local' => null], $this->verdictsOf($path, $reader, $files, false, true));
        $sameFile = array_merge($this->writerFixture(), ['  local:', '    extends: {service: writer}']);
        $this->assertSame(['writer' => null, 'local' => null], $this->verdictsOf($path, $sameFile, [], false, true));

        $database = [
            'services:',
            '  database: &declared-database',
            '    profiles: [test]',
            '    volumes:',
            '      - ./config.cnf:/etc/mysql/conf.d/test.cnf:ro',
            '  inherited:',
            '    <<: *declared-database',
            '  outside:',
            '    profiles: [local]',
            '    volumes:',
            '      - ../..:/hilos:rw',
        ];
        $this->assertSame(['database' => null, 'inherited' => null],
            $this->verdictsOf($path, $database, [], false, true, ['test']));
        foreach (['*missing', '[*declared-database]'] as $alias) {
            $unknown = array_map(static fn(string $line): string => str_replace('*declared-database', $alias, $line), $database);
            $message = $this->verdictsOf($path, $unknown, [], false, true, ['test'])['inherited'];
            $this->assertIsString($message);
            $this->assertStringContainsString("{$path}:6: the guard cannot read the service inherited:", $message);
            $this->assertStringContainsString(self::RULES, $message);
        }
        $writable = array_map(static fn(string $line): string => str_replace('test.cnf:ro', 'test.cnf:rw', $line), $database);
        $message = $this->verdictsOf($path, $writable, [], false, true, ['test'])['inherited'];
        $this->assertIsString($message);
        $this->assertStringContainsString('entrypoint', $message);
    }

    /** @return list<string> A complete writer used by ownership refusal and inheritance fixtures */
    private function writerFixture(): array
    {
        return [
            'services:',
            '  writer:',
            '    entrypoint: [sh, /hilos/framework/docker/test-writer-entrypoint.sh]',
            '    environment:',
            '      HILOS_TEST_OWNER_ROOT: /hilos',
            '      HILOS_TEST_WRITE_DIRS: /hilos/vendor /tmp/cache',
            '    volumes:',
            '      - ../..:/hilos:rw',
        ];
    }

    /**
     * @param string $cwd Registry demo directory
     * @param bool $present Whether the data marker exists
     * @return ?string The missing marker's address, or null
     */
    private function markerVerdictOf(string $cwd, bool $present): ?string
    {
        return $present ? null : "{$cwd}/data/.gitignore: a demo with a test stand needs its data/ parent in Git; see " . self::RULES;
    }

    /**
     * The verdict on every service of the demo stands that mounts the project or that the guard
     * cannot read.
     *
     * @param bool $ownership Include framework/frontend writers and demo data-parent markers
     * @return list<?string> One sentence per failure, null per service that holds the rule
     */
    private function standVerdicts(bool $ownership = false): array
    {
        $verdicts = [];
        foreach ($ownership ? standRegistry($this->repositoryRoot()) : $this->demoStands() as $stand) {
            if ($ownership && str_starts_with($stand['cwd'], self::DEMO_ROOT)) {
                $verdicts[] = $this->markerVerdictOf($stand['cwd'], is_file($this->repositoryRoot() . '/' . $stand['cwd'] . '/data/.gitignore'));
            }
            $path = $this->normalized($stand['cwd'] . '/' . $stand['composeFile']);
            $verdicts = array_merge($verdicts, array_values($this->verdictsOf(
                $path,
                $this->linesOf($path),
                [],
                in_array($stand['composeFile'], self::CLUSTER_COMPOSE_FILES, true),
                $ownership,
                $stand['profiles'],
            )));
        }

        return $verdicts;
    }

    /**
     * @param string $path Repository-relative file path
     * @param array<int, string> $lines Compose text
     * @param array<string, array<int, string>> $files Available parts
     * @param bool $cluster Whether only named data volumes isolate this stand
     * @param bool $ownership Whether to check clone writers as well as demo data isolation
     * @param list<string> $profiles Only these profiles belong to a profile-scoped registry row
     * @return array<string, ?string> Service => refusal, or null for a service that holds the rule
     */
    private function verdictsOf(
        string $path,
        array $lines,
        array $files,
        bool $cluster,
        bool $ownership = false,
        array $profiles = [],
    ): array {
        $verdicts = [];
        foreach ($this->serviceLayers($path, $lines, $files) as $name => $service) {
            $place = $service['place'];
            $layers = $service['layers'];
            $keys = array_replace([], ...array_column($layers, 'keys'));
            $error = $service['error'];
            if ($profiles !== [] && ($error === null || isset($keys['profiles']))) {
                $active = $this->profilesOf($keys);
                if (is_string($active)) {
                    $error = $active;
                } elseif (array_intersect($profiles, $active) === []) {
                    continue;
                }
            }
            $mounts = $error ?? $this->mountsOfService($layers);
            if (is_string($mounts)) {
                $verdicts[$name] = "{$place}: the guard cannot read the service {$name}: {$mounts}; use block service keys, "
                    . 'short volume lines, one-level extends or a declared same-file service anchor; see ' . self::RULES;
                continue;
            }
            if (isset($mounts[self::PROJECT_TARGET])) {
                $wrong = $this->wrongMountsOf($mounts, $cluster);
                if ($wrong !== []) {
                    $verdicts[$name] = "{$place}: the service {$name} mounts the project at " . self::PROJECT_TARGET . ' but '
                        . implode(', and ', $wrong) . '; mount `' . self::TEST_ROOT . ':' . self::DATA_ROOT
                        . '` — a cluster stand: its own named data volume — and keep every other mount under ' . self::DATA_ROOT
                        . ' inside it; see ' . self::RULES;
                    continue;
                }
                $verdicts[$name] = null;
            }
            if ($ownership) {
                $reason = $this->ownershipProblemOf($keys, $layers, $mounts);
                $verdicts[$name] = $reason === null ? null : "{$place}: the service {$name}: {$reason}; see " . self::RULES;
            }
        }

        return $verdicts;
    }

    /**
     * @param array<string, array<int, string>> $keys Effective service fields
     * @return list<string>|string Profiles, or an unreadable form
     */
    private function profilesOf(array $keys): array|string
    {
        if (!isset($keys['profiles'])) {
            return [];
        }
        $value = $this->valueOf($keys['profiles']);
        if ($value !== null && preg_match('/^\[([A-Za-z0-9_, "\'-]*)\]$/', $value, $match) === 1) {
            return array_map(static fn(string $profile): string => trim($profile, " \"'"), explode(',', $match[1]));
        }
        if ($value !== null) {
            return 'its profiles are not a literal list';
        }
        $profiles = [];
        foreach (array_slice($keys['profiles'], 1) as $line) {
            if (preg_match('/^\s*-\s+["\']?([A-Za-z0-9_-]+)["\']?\s*$/', $line, $match) !== 1) {
                return 'its profiles are not a literal list';
            }
            $profiles[] = $match[1];
        }

        return $profiles;
    }

    /**
     * @param array $keys Effective scalar fields
     * @param list<array{path: string, keys: array, inherited: bool}> $layers Ordered service declarations
     * @param array<string, array{source: string, target: string, mode: string, part?: string}> $mounts Effective mounts
     * @return ?string The ownership refusal, or null for a reader or a declared writer
     */
    private function ownershipProblemOf(array $keys, array $layers, array $mounts): ?string
    {
        $writers = array_filter($mounts, function (array $mount): bool {
            if (in_array('ro', explode(',', $mount['mode']), true)
                || preg_match(self::VOLUME_NAME, $mount['source']) === 1 || str_starts_with($mount['source'], '/')
                || preg_match('~^\$\{[A-Za-z_][A-Za-z0-9_]*:-/~', $mount['source']) === 1) {
                return false;
            }
            $source = $this->normalized(dirname($mount['path']) . '/' . $mount['source']);

            return $source !== '..' && !str_starts_with($source, '../');
        });
        if ($writers === []) {
            return null;
        }
        $entrypoint = isset($keys['entrypoint']) ? $this->valueOf($keys['entrypoint']) : null;
        if ($entrypoint === null || preg_match('~^\[\s*["\']?sh["\']?\s*,\s*["\']?'
                . preg_quote(self::WRITER, '~') . '["\']?\s*\]$~', $entrypoint) !== 1) {
            return 'a writable clone bind requires the shared test-writer entrypoint';
        }
        $environment = $this->environmentOf($layers, [self::OWNER_ROOT, self::WRITE_DIRS]);
        if (is_string($environment)) {
            return $environment;
        }
        $owner = $environment[self::OWNER_ROOT] ?? null;
        if (!in_array($owner, ['/app', '/hilos'], true) || !isset($writers[$owner])) {
            return self::OWNER_ROOT . ' must name a writable clone bind root /app or /hilos; a read-only root does not cover child rw binds';
        }
        $dirs = $environment[self::WRITE_DIRS] ?? null;
        if ($dirs === null || trim($dirs) === '' || preg_match(self::VARIABLE, $dirs) === 1) {
            return self::WRITE_DIRS . ' must declare nonempty literal output directories';
        }
        foreach (preg_split('/\s+/', $dirs) as $dir) {
            if (!str_starts_with($dir, '/') || in_array($dir, ['/', '/app', '/hilos'], true)
                || preg_match('~(?:^|/)(?:\.\.?|\.git)(?:/|$)|//|/$~', $dir) === 1
                || (str_ends_with($dir, '/data') && ($dir !== '/app/data' || $owner !== '/app'))) {
                return self::WRITE_DIRS . " contains an unsafe output directory `{$dir}`";
            }
        }

        return null;
    }

    /**
     * What one service's mounts break of the rule.
     *
     * @param array<string, array{source: string, target: string, mode: string, part?: string}> $mounts Target => mount, the project among them
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
     * @param list<array{path: string, keys: array, inherited: bool}> $layers Ordered service declarations
     * @return array<string, array>|string Mounts by target with their source files, or refusal
     */
    private function mountsOfService(array $layers): array|string
    {
        $mounts = [];
        foreach ($layers as $layer) {
            $keys = $layer['keys'];
            $own = $this->mountsOf($layer['path'], $keys);
            if (is_string($own)) {
                return $own;
            }
            if (in_array($this->volumesValueOf($keys), self::VOLUMES_RESETS, true)) {
                $mounts = [];
            }
            foreach ($own as $target => $mount) {
                $mounts[$target] = $mount + ['path' => $layer['path']];
                if ($layer['inherited']) {
                    $mounts[$target]['part'] = $layer['path'];
                }
            }
        }

        return $mounts;
    }

    /**
     * The mounts one service writes itself.
     *
     * @param string $path Repository-relative path of the file that declares the service
     * @param array<string, array<int, string>> $keys The keys of the service, as {@see entriesOf()} gives them
     * @return array<string, array{source: string, target: string, mode: string}>|string Target => mount, or why the guard cannot read them
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

            $mode = $matches['mode'] ?? 'rw';
            if (array_diff(explode(',', $mode), ['ro', 'rw', 'z', 'Z', 'cached', 'delegated', 'consistent']) !== []
                || (in_array('ro', explode(',', $mode), true) && in_array('rw', explode(',', $mode), true))) {
                return "the volume line {$path}:{$number} has an unreadable mode `{$mode}`";
            }
            $target = rtrim($matches['target'], '/');
            $mounts[$target] = ['source' => rtrim($matches['source'], '/'), 'target' => $target, 'mode' => $mode];
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
}

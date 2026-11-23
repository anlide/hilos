<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Tests\Fixtures\StandComposeReader;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/stand-registry.php';

/**
 * Holds every demo stand to a log rotation schedule no run can meet.
 *
 * The product rotates a node's live logs at night, and a stand takes that default unless it says
 * otherwise. A run that stands over the night then loses the `daemon.log` it reads, and what goes
 * red is whichever scenario was reading at that moment. A test that needs a rotation calls one
 * itself, so a stand has no use for the night - but it keeps the rule, which the Cron screen and
 * its scenarios read, by moving it to the 29th of February.
 *
 * The walk starts from the registry because a stand is born as a row of it, so a new demo meets
 * this question the moment it gets one. The rows outside `demo/` run no demo's daemon.
 *
 * Ordinary stands declare the schedule on each labeled daemon. Cluster nodes inherit env files
 * and service environments through their Compose entry's ordered include group.
 */
final class StandLogRotationGuardTest extends TestCase
{
    use StandComposeReader;

    /** The value that moves a stand's rotation rule out of reach of any run. */
    private const string CRON = '0 3 29 2 *';

    /** The assignment required in a stand environment. */
    private const string SCHEDULE = 'LOG_ROTATION_CRON=' . self::CRON;

    /** The harness identifies a node by its enabled flag and nonempty id. */
    private const array NODE_ENV = ['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'LOG_ROTATION_CRON'];

    /** Compose trims unquoted suffixes with Go's unicode.IsSpace. */
    private const string DOTENV_TRAILING_SPACE =
        '/[\x09-\x0D\x20\x85\xA0\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+\z/u';

    /** The same value as a line of a service's environment. */
    private const string COMPOSE_LINE = '- ' . self::SCHEDULE;

    /** The label that tells a stand's daemon from its other services. */
    private const string APP_LABEL = 'hilos.role: app';

    /** Where a registry row of a demo starts. */
    private const string DEMO_ROOT = 'demo/';

    /** The rule a message sends its reader to. */
    private const string RULES = 'docs/agents/testing/stand-compose.md';

    /** A stand that lost the line goes back to rotating under a run at night. */
    public function testEveryDemoStandHoldsTheScheduleOutOfReach(): void
    {
        $problems = [];
        foreach ($this->demoStands() as $stand) {
            $path = $stand['cwd'] . '/' . $stand['composeFile'];
            $lines = $this->linesOf($path);
            $problem = in_array($stand['composeFile'], self::CLUSTER_COMPOSE_FILES, true)
                ? $this->clusterProblemOf($path, $lines) : $this->problemOf($path, $lines);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        $this->assertSame([], $problems, implode(PHP_EOL, $problems));
    }

    /** The message says what to write and where, and the line of another service does not count. */
    public function testAFileWithoutTheLineIsNamed(): void
    {
        $compose = 'demo/shop/docker/docker-compose.test.yml';
        $message = $this->problemOf($compose, [
            'services:',
            '  shop-daemon-test:',
            '    labels:',
            '      hilos.role: app',
            '    environment:',
            '      - APP_ENV=test',
            '  shop-cli-test:',
            '    environment:',
            '      - APP_ENV=test',
            '      - LOG_ROTATION_CRON=0 3 29 2 *',
        ]);
        $this->assertNotNull($message);
        foreach ([$compose, 'shop-daemon-test', '- LOG_ROTATION_CRON=0 3 29 2 *', 'docs/agents/testing/stand-compose.md'] as $expected) {
            $this->assertStringContainsString($expected, $message);
        }
    }

    /** A browser-only override reaches every shop node while the scenario root keeps its own schedule. */
    public function testOnlyTheBrowserEntryReadsTheLateSchedule(): void
    {
        $docker = 'demo/ecommerce-shop/docker';
        $browser = "{$docker}/docker-compose.cluster-browser.yml";
        $scenario = "{$docker}/docker-compose.cluster.yml";
        $files = ["{$docker}/cluster/browser.env" => ['LOG_ROTATION_CRON=0 3 * * *']];
        $this->assertNull($this->clusterProblemOf($scenario, $this->linesOf($scenario), $files));
        $message = $this->clusterProblemOf($browser, $this->linesOf($browser), $files);
        $this->assertIsString($message);
        foreach (['m1', 'm2', 's1', 's2'] as $node) {
            $this->assertStringContainsString("ecommerce-shop-cluster-{$node}", $message);
        }
    }

    /** A different spelling of the daemon path leaves s1 in both real shop rosters. */
    public function testOneNodeWithADailyScheduleIsNamedInEitherRoster(): void
    {
        $docker = 'demo/ecommerce-shop/docker';
        $files = [
            "{$docker}/cluster/override.yml" => [
                'services:',
                '  ecommerce-shop-cluster-s1:',
                '    command: [php, backend/Bootstrap/./docker.php]',
                '    env_file:',
                '      - ./daily.env',
            ],
            "{$docker}/cluster/daily.env" => ['LOG_ROTATION_CRON=0 3 * * *'],
        ];
        foreach (self::CLUSTER_COMPOSE_FILES as $entry) {
            $path = 'demo/ecommerce-shop/' . $entry;
            $lines = [...$this->linesOf($path), '      - ./cluster/override.yml'];
            $this->assertSame(
                "{$path}: ecommerce-shop-cluster-s1 must set " . self::SCHEDULE . '; see ' . self::RULES,
                $this->clusterProblemOf($path, $lines, $files),
            );
        }
    }

    /** Only s1 has the daily cron, so skipping it would leave a green roster. */
    public function testListCommentKeepsTheNodeInTheRoster(): void
    {
        foreach (self::CLUSTER_COMPOSE_FILES as $entry) {
            $path = 'demo/ecommerce-shop/' . $entry;
            $layer = dirname($path) . '/cluster/extra.yml';
            $lines = [...$this->linesOf($path), '      - ./cluster/extra.yml'];
            $files = [$layer => [
                'services:',
                '  ecommerce-shop-cluster-s1:',
                '    environment:',
                '      - CLUSTER_ENABLED=true # enabled on this stand',
                '      - LOG_ROTATION_CRON=0 3 * * *',
            ]];
            $this->assertSame(
                "{$path}: ecommerce-shop-cluster-s1 must set " . self::SCHEDULE . '; see ' . self::RULES,
                $this->clusterProblemOf($path, $lines, $files),
            );
            $files[$layer][4] = '      - "' . self::SCHEDULE . '" # canonical';
            $this->assertNull($this->clusterProblemOf($path, $lines, $files));
        }
    }

    /** The child takes its cron from either a same-file parent or a literal sibling. */
    #[TestWith(['{service: template}', true])]
    #[TestWith(['{file: "node.yml", service: template}', false])]
    public function testReadsALiteralExtends(string $extends, bool $sameFile): void
    {
        $path = 'demo/shop/docker/cluster/local.yml';
        $parent = ['  template:', '    environment:', '      - ' . self::SCHEDULE];
        $lines = [
            'services:',
            ...($sameFile ? $parent : []),
            '  s1:',
            "    extends: {$extends}",
            '    environment:',
            '      - CLUSTER_ENABLED=true',
            '      - CLUSTER_NODE_ID=s1',
        ];
        $files = [dirname($path) . '/node.yml' => ['services:', ...$parent]];
        $this->assertNull($this->clusterProblemOf($path, $lines, $files));
    }

    /** The source spelling must identify a sibling before the reader resolves any path segments. */
    #[DataProvider('nonlocalParents')]
    public function testRefusesTheOriginalExtendsPath(string $entry, string $parent): void
    {
        $path = 'demo/ecommerce-shop/' . $entry;
        $local = dirname($path) . '/cluster/local.yml';
        $lines = $this->linesOf($local);
        $service = array_search('  ecommerce-shop-cluster-s1:', $lines, true);
        $this->assertIsInt($service);
        $lines[$service + 1] = "    extends: {file: {$parent}, service: node}";
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("{$path}: ecommerce-shop-cluster-s1 in {$local}: unsupported extends file {$parent}");
        $this->clusterProblemOf($path, $this->linesOf($path), [$local => $lines]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonlocalParents(): iterable
    {
        foreach (self::CLUSTER_COMPOSE_FILES as $entry) {
            foreach (['/node.yml', 'child/../node.yml', './/node.yml'] as $parent) {
                yield "{$entry} {$parent}" => [$entry, $parent];
            }
        }
    }

    /** The cron check uses the value after Compose handles dotenv whitespace and inline comments. */
    #[DataProvider('dotenvSchedules')]
    public function testChecksTheEffectiveDotenvSchedule(string $enabled, string $cron, bool $canonical): void
    {
        foreach (self::CLUSTER_COMPOSE_FILES as $entry) {
            $path = 'demo/ecommerce-shop/' . $entry;
            $env = dirname($path) . '/cluster/nodes/s1.env';
            $files = [$env => [...$this->linesOf($env), "CLUSTER_ENABLED={$enabled}", "LOG_ROTATION_CRON={$cron}"]];
            $expected = $canonical ? null : "{$path}: ecommerce-shop-cluster-s1 must set " . self::SCHEDULE . '; see ' . self::RULES;
            $this->assertSame($expected, $this->clusterProblemOf($path, $this->linesOf($path), $files));
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function dotenvSchedules(): array
    {
        return [
            'enabled after NBSP' => ["\u{00A0}true", '0 3 * * *', false],
            'quoted enabled after NBSP' => ["\u{00A0}'true'", '0 3 * * *', false],
            'tab before hash' => ['true', self::CRON . "\t# note", false],
            'trailing ASCII space' => ['true', self::CRON . ' ', true],
            'trailing Unicode spaces' => ['true', self::CRON . "\u{0085}\u{2007}\u{3000}", true],
            'trailing Mongolian vowel separator' => ['true', self::CRON . "\u{180E}", false],
            'literal and ASCII comment' => ["\u{00A0}'true'", self::CRON . " # \u{00A0}'\$ignored\\", true],
        ];
    }

    /** The apparent assignment on the next physical line is still part of the foreign value. */
    #[TestWith([self::CLUSTER_COMPOSE_FILES[0]])]
    #[TestWith([self::CLUSTER_COMPOSE_FILES[1]])]
    public function testRefusesAMultilineValueAfterDotenvWhitespace(string $entry): void
    {
        $path = 'demo/ecommerce-shop/' . $entry;
        $local = dirname($path) . '/cluster/local.yml';
        $env = dirname($local) . '/nodes/s1.env';
        $files = [$env => [
            ...$this->linesOf($env),
            "STAND_NOTE=\u{00A0}'first line",
            'CLUSTER_ENABLED=false',
            "STAND_END=last'",
            'LOG_ROTATION_CRON=0 3 * * *',
        ]];
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("{$path}: ecommerce-shop-cluster-s1 in {$local} env_file {$env} STAND_NOTE");
        $this->clusterProblemOf($path, $this->linesOf($path), $files);
    }

    /**
     * Every fixture keeps the other shop nodes intact so an unreadable layer must identify itself.
     *
     * @param string $part File relative to the cluster directory
     * @param list<string> $extra Contents of the overlay, or lines appended to a registered part
     * @param string $diagnostic Place and field the static reader cannot resolve
     */
    #[DataProvider('unreadableLayers')]
    public function testRefusesAnUnverifiedRoster(string $part, array $extra, string $diagnostic): void
    {
        $path = 'demo/ecommerce-shop/docker/docker-compose.cluster.yml';
        $cluster = dirname($path) . '/cluster';
        $lines = [...$this->linesOf($path), '      - ./cluster/extra.yml'];
        $file = "{$cluster}/{$part}";
        $files = ["{$cluster}/extra.yml" => [
            'services:',
            '  ecommerce-shop-cluster-s1:',
            '    environment:',
            '      LOG_ROTATION_CRON: "0 3 * * *"',
        ]];
        $files["{$cluster}/extra-node.yml"] = [
            'services:',
            '  s3:',
            '    image: test',
            '    environment:',
            '      - CLUSTER_ENABLED=true',
            '      - CLUSTER_NODE_ID=s3',
            '      - LOG_ROTATION_CRON=0 3 * * *',
        ];
        $files[$file] = $part === 'extra.yml' ? $extra : [...$this->linesOf($file), ...$extra];
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("{$path}: {$diagnostic}");
        $this->clusterProblemOf($path, $lines, $files);
    }

    /** @return array<string, array{string, list<string>, string}> */
    public static function unreadableLayers(): array
    {
        $cluster = 'demo/ecommerce-shop/docker/cluster';
        $service = "ecommerce-shop-cluster-s1 in {$cluster}/extra.yml";
        $envFile = "ecommerce-shop-cluster-s1 in {$cluster}/local.yml env_file {$cluster}/nodes/s1.env";
        $node = ['services:', '  ecommerce-shop-cluster-s1:'];
        $environment = [...$node, '    environment:', '      LOG_ROTATION_CRON: "0 3 * * *"'];
        $flow = 'services: {s3: {image: test, environment: {CLUSTER_ENABLED: "true", CLUSTER_NODE_ID: s3, LOG_ROTATION_CRON: "0 3 * * *"}}}';
        return [
            'substitution' => ['extra.yml', [...$environment, '      CLUSTER_ENABLED: "${FLAG:-true}"'], "{$service} environment CLUSTER_ENABLED"],
            'mapping lookup' => ['extra.yml', [...$environment, '      CLUSTER_NODE_ID:'], "{$service} environment CLUSTER_NODE_ID"],
            'list lookup' => ['extra.yml', [...$node, '    environment:', '      - CLUSTER_ENABLED'], "{$service} environment CLUSTER_ENABLED"],
            'tagged scalar' => ['extra.yml', [...$environment, '      CLUSTER_ENABLED: !!str true'], "{$service} environment CLUSTER_ENABLED"],
            'dotenv lookup' => ['nodes/s1.env', ['CLUSTER_ENABLED'], "{$envFile} CLUSTER_ENABLED"],
            'dotenv expression' => ['nodes/s1.env', ['CLUSTER_ENABLED=${FLAG:-true}'], "{$envFile} CLUSTER_ENABLED"],
            'foreign multiline' => ['nodes/s1.env', ["STAND_NOTE='first line", 'CLUSTER_ENABLED=false', "last line'"], "{$envFile} STAND_NOTE"],
            'foreign lookup' => ['nodes/s1.env', ['STAND_NOTE'], "{$envFile} STAND_NOTE"],
            'nested include' => ['local.yml', ['include: [./extra-node.yml]'], "{$cluster}/local.yml include"],
            'inherited include' => ['node.yml', ['include: [./extra-node.yml]'], "ecommerce-shop-cluster-m1 in {$cluster}/node.yml include"],
            'flow services' => ['extra.yml', [$flow], "{$cluster}/extra.yml services"],
            'repeated extends' => ['extra.yml', [...$node, '    extends: {file: ./node.yml, service: node}'], "{$service} extends"],
            'long env file' => ['extra.yml', [...$node, '    env_file:', '      - path: ./common.env'], "{$service} env_file"],
        ];
    }

    /** Both Compose tags discard earlier files, including their node identity and schedule assignments. */
    public function testEnvFileReplacementKeepsOnlyTheListedFiles(): void
    {
        $path = 'demo/ecommerce-shop/docker/docker-compose.cluster.yml';
        $layer = dirname($path) . '/cluster/extra.yml';
        $lines = [...$this->linesOf($path), '      - ./cluster/extra.yml'];
        $service = ['services:', '  ecommerce-shop-cluster-s1:'];
        $files = [
            $layer => [...$service, '    env_file: [./daily.env]'],
            dirname($layer) . '/daily.env' => ['LOG_ROTATION_CRON=0 3 * * *'],
        ];
        $this->assertSame(
            "{$path}: ecommerce-shop-cluster-s1 must set " . self::SCHEDULE . '; see ' . self::RULES,
            $this->clusterProblemOf($path, $lines, $files),
        );
        $early = dirname($layer) . '/nodes/s1.env';
        $files[$early] = [...$this->linesOf($early), 'LOG_ROTATION_CRON=0 3 * * *'];
        foreach ([['    env_file: !reset []'], ['    env_file: !override', '      - ./daily.env']] as $replacement) {
            $files[$layer] = [...$service, ...$replacement];
            $this->assertNull($this->clusterProblemOf($path, $lines, $files));
        }
        $files[$layer] = [...$service, '    env_file: [./daily.env]', '    environment:', '      - ' . self::SCHEDULE];
        $this->assertNull($this->clusterProblemOf($path, $lines, $files));
    }

    /** The matrix also starts the tracker node under the stranger profile. */
    public function testTheProfiledStrangerMustKeepTheSchedule(): void
    {
        $path = 'demo/binance-btc-tracker/docker/docker-compose.cluster.yml';
        $file = dirname($path) . '/cluster/nodes/x1.env';
        $files = [$file => [...$this->linesOf($file), 'LOG_ROTATION_CRON=0 3 * * *']];
        $this->assertSame(
            "{$path}: binance-btc-tracker-cluster-x1 must set " . self::SCHEDULE . '; see ' . self::RULES,
            $this->clusterProblemOf($path, $this->linesOf($path), $files),
        );
    }

    /** A commented common value supplies no schedule. The service environment outranks every env file. */
    public function testTheNodeRequiresAnEffectiveSchedule(): void
    {
        $path = 'demo/shop/docker/cluster/local.yml';
        $lines = [
            'services:',
            '  node:',
            '    command: [php, backend/Bootstrap/docker.php]',
            '    env_file:',
            '      - ./common.env',
        ];
        $files = ['demo/shop/docker/cluster/common.env' => ['CLUSTER_ENABLED=true', 'CLUSTER_NODE_ID=node', '# ' . self::SCHEDULE]];
        $message = $this->clusterProblemOf($path, $lines, $files);
        $this->assertNotNull($message);
        foreach ([$path, 'node', self::SCHEDULE, self::RULES] as $expected) {
            $this->assertStringContainsString($expected, $message);
        }
        $this->assertNull($this->clusterProblemOf($path, [...$lines, '    environment:', '      - ' . self::SCHEDULE], $files));
    }

    /** A guard that stopped seeing the stands would otherwise stay green over nothing. */
    public function testTheGuardSeesTheDemoStands(): void
    {
        $this->assertGreaterThan(0, count($this->demoStands()), 'no demo stand was found at all - the reader stopped seeing them');
    }

    /**
     * What one stand's file lacks, as the sentence for whoever adds the stand.
     *
     * @param string $path Repository-relative path of the file; its ending says which form it owes
     * @param array<int, string> $lines The lines of that file
     * @return ?string The sentence, or null when the file holds the schedule out of reach
     */
    private function problemOf(string $path, array $lines): ?string
    {
        $found = false;
        foreach ($this->servicesOf($lines) as $service => $body) {
            $trimmed = array_map('trim', $body);
            if (in_array(self::APP_LABEL, $trimmed, true)) {
                $found = true;
                if (!in_array(self::COMPOSE_LINE, $trimmed, true)) {
                    return "{$path}: the service {$service} rotates its logs at night, under a run. Add the line `"
                        . self::COMPOSE_LINE . '` to its environment; see ' . self::RULES;
                }
            }
        }
        if ($found) {
            return null;
        }

        return "{$path}: no service is labeled `" . self::APP_LABEL . '`, so none can be held to the line `'
            . self::COMPOSE_LINE . '`; label the daemon and add the line to its environment; see ' . self::RULES;
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
     * @param string $path Compose entry point
     * @param array<int, string> $lines Entry text
     * @param array<string, list<string>> $files File contents supplied by a fixture
     * @return ?string Nodes whose effective schedule is missing or reachable
     */
    private function clusterProblemOf(string $path, array $lines, array $files = []): ?string
    {
        $nodes = [];
        foreach ($this->serviceLayers($path, $lines, $files, verifyRoster: true) as $name => $service) {
            $source = "{$path}: {$name}";
            $environment = [];
            foreach ($service['layers'] as $layer) {
                if (isset($layer['keys']['environment'])) {
                    $place = "{$source} in {$layer['path']} environment";
                    $environment = $this->nodeEnvironment($layer['keys']['environment'], $place) + $environment;
                }
            }
            $environment += $this->fileEnvironmentOf($service['layers'], $files, $source);
            $nodeId = $environment['CLUSTER_NODE_ID'] ?? null;
            if (($environment['CLUSTER_ENABLED'] ?? null) !== 'true' || $nodeId === null || $nodeId === '') {
                continue;
            }

            $nodes[$name] = $environment['LOG_ROTATION_CRON'] ?? null;
        }
        $this->assertNotEmpty($nodes, "{$path}: no cluster daemon found");
        $wrong = array_keys(array_filter($nodes, static fn(?string $schedule): bool => $schedule !== self::CRON));

        return $wrong === [] ? null : "{$path}: " . implode(', ', $wrong) . ' must set ' . self::SCHEDULE . '; see ' . self::RULES;
    }

    /**
     * Read only literal roster fields. Compose resolves interpolation and pass-through on its host.
     *
     * @param array<int, string> $entry Environment declaration and its body
     * @param string $place Entry, service and declaring layer
     * @return array<string, string> Direct assignments in this layer
     */
    private function nodeEnvironment(array $entry, string $place): array
    {
        $this->assertNull($this->valueOf($entry), "{$place}: expected a block list or mapping");
        $lines = array_slice($entry, 1, null, true);
        $values = [];
        if ($lines !== [] && str_starts_with(ltrim(reset($lines)), '-')) {
            $indent = strspn(reset($lines), ' ');
            foreach ($lines as $line) {
                $this->assertSame($indent, strspn($line, ' '), "{$place}: multiline list item");
                $this->assertStringStartsWith('- ', ltrim($line), $place);
                $raw = trim(substr(ltrim($line), 2));
                preg_match('/^["\x27]?([A-Za-z_][A-Za-z0-9_]*)=/', $raw, $name);
                $item = $this->yamlLiteral($raw, isset($name[1]) ? "{$place} {$name[1]}" : $place);
                $assignment = explode('=', $item, 2);
                $key = $assignment[0];
                $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key, "{$place}: unresolved variable name");
                if (in_array($key, self::NODE_ENV, true)) {
                    $this->assertArrayNotHasKey($key, $values, "{$place} {$key}: duplicate list entry");
                    $this->assertArrayHasKey(1, $assignment, "{$place} {$key}: host lookup");
                    $this->assertStringNotContainsString('$', $assignment[1], "{$place} {$key}: interpolation");
                    $values[$key] = $assignment[1];
                }
            }

            return $values;
        }

        $fields = $this->entriesOf($lines, $place);
        $this->assertArrayNotHasKey(self::MERGE_KEY, $fields, "{$place}: unsupported mapping merge");
        foreach (array_intersect(self::NODE_ENV, array_keys($fields)) as $key) {
            $field = "{$place} {$key}";
            $this->assertCount(1, $fields[$key], "{$field}: expected a single-line literal");
            preg_match(self::KEY, reset($fields[$key]), $match);
            $this->assertArrayHasKey(2, $match, "{$field}: host lookup");
            $raw = trim($match[2]);
            $value = $this->yamlLiteral($raw, $field);
            if (!str_starts_with($raw, '"') && !str_starts_with($raw, "'") && !in_array($value, ['true', 'false'], true)) {
                $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9.* ,_\/-]*$/iD', $value, "{$field}: unsupported plain scalar");
                $this->assertDoesNotMatchRegularExpression('/^(?:null|yes|no|on|off|true|false)$/iD', $value, "{$field}: use a quoted literal");
            }
            $this->assertStringNotContainsString('$', $value, "{$field}: interpolation");
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param string $raw One YAML scalar after the key or list marker
     * @param string $place Field or list containing the scalar
     * @return string Literal text including any spaces inside quotes
     */
    private function yamlLiteral(string $raw, string $place): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '"') || str_starts_with($raw, "'")) {
            $matched = preg_match('~^(?|"([^"\\\\]*)"|\x27([^\x27]*)\x27)(?:\s+#.*)?$~D', $raw, $match);
            $this->assertSame(1, $matched, "{$place}: unsupported quoted scalar");
            return $match[1];
        }

        return rtrim(preg_replace('/(?:^|\s+)#.*$/', '', $raw));
    }

    /**
     * @param list<array{path: string, keys: array}> $layers Ordered service declarations
     * @param array<string, list<string>> $files File contents supplied by a fixture
     * @param string $source Entry and exported service for diagnostics
     * @return array<string, string> Node identity and schedule from the effective env_file list
     */
    private function fileEnvironmentOf(array $layers, array $files, string $source): array
    {
        $paths = [];
        foreach ($layers as $layer) {
            $entry = $layer['keys']['env_file'] ?? null;
            if ($entry === null) {
                continue;
            }
            $place = "{$source} in {$layer['path']} env_file";
            $value = $this->valueOf($entry);
            if ($value !== null && preg_match('/^\[([^\]]*)\]$/', $value, $match) === 1) {
                $this->assertCount(1, $entry, $place);
                $entries = trim($match[1]) === '' ? [] : array_map('trim', explode(',', $match[1]));
            } else {
                $this->assertContains($value, [null, '!override', '!reset []'], $place);
                if ($value !== null) {
                    $paths = [];
                }
                $entries = [];
                foreach (array_slice($entry, 1) as $line) {
                    $this->assertMatchesRegularExpression('/^\s*- \S+$/', $line, $place);
                    $entries[] = substr(trim($line), 2);
                }
            }
            foreach ($entries as $path) {
                $paths[] = [$layer['path'], $path];
            }
        }

        $values = [];
        foreach ($paths as [$layer, $path]) {
            $place = "{$source} in {$layer} env_file";
            $this->assertMatchesRegularExpression('~^(?!/)[A-Za-z0-9_./-]+$~D', $path, "{$place}: expected a literal relative path");
            $file = $this->normalized(dirname($layer) . '/' . $path);
            $this->assertTrue(isset($files[$file]) || is_file($this->repositoryRoot() . '/' . $file), "{$place} {$file}: missing file");
            $values = $this->dotenvEnvironment($files[$file] ?? $this->linesOf($file), "{$place} {$file}") + $values;
        }

        return $values;
    }

    /**
     * @param list<string> $lines Physical lines from one effective env_file
     * @param string $place Compose entry, service, declaring layer and dotenv path
     * @return array<string, string> Literal assignments to the three roster fields
     */
    private function dotenvEnvironment(array $lines, string $place): array
    {
        $values = [];
        foreach ($lines as $line) {
            $line = ltrim($line, " \t\v\f\r");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parsed = preg_match('~^(?:export[ \t\f\r]\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*(?:[=:](.*)|(?:#.*)?)$~D', $line, $match);
            $this->assertSame(1, $parsed, "{$place}: unsupported dotenv declaration");
            $key = $match[1];
            $field = "{$place} {$key}";
            $this->assertArrayHasKey(2, $match, "{$field}: host lookup");
            // Compose skips this set before it decides whether the value opens a quoted span.
            $raw = mb_ltrim($match[2], " \t\v\f\r\u{0085}\u{00A0}", 'UTF-8');
            $quoted = str_starts_with($raw, '"') || str_starts_with($raw, "'");
            if ($quoted) {
                // Check foreign fields too: an open quote owns the following physical lines.
                $parsed = preg_match('~^(?|"([^"\\\\]*)"|\x27([^\x27\\\\]*)\x27)\s*(?:#.*)?$~D', $raw, $literal);
                $this->assertSame(1, $parsed, "{$field}: expected a single-line quoted dotenv value");
            }
            if (in_array($key, self::NODE_ENV, true)) {
                // An unquoted comment starts at the first ASCII space followed immediately by a hash.
                $value = $quoted ? $literal[1] : preg_replace(self::DOTENV_TRAILING_SPACE, '', explode(' #', $raw, 2)[0]);
                $this->assertIsString($value, "{$field}: expected a UTF-8 literal");
                if (!str_starts_with($raw, "'")) {
                    $this->assertDoesNotMatchRegularExpression('~[$\\\\]~', $value, "{$field}: interpolation or escape");
                }
                $values[$key] = $value;
            }
        }

        return $values;
    }
}

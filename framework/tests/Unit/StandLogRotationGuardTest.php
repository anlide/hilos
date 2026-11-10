<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

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
 * This is a check of the text of the files, not of the model compose resolves out of them. They
 * are read line by line because the repository has no YAML parser, the same way the cluster stand
 * guard reads them. A cluster stand says the line once for all its nodes, in its `common.env`,
 * which the compose root reaches only through its parts: so that file is read directly.
 */
final class StandLogRotationGuardTest extends TestCase
{
    /** The value that moves a stand's rotation rule out of reach of any run. */
    private const string SCHEDULE = 'LOG_ROTATION_CRON=0 3 29 2 *';

    /** The same value as a line of a service's environment. */
    private const string COMPOSE_LINE = '- ' . self::SCHEDULE;

    /** The label that tells a stand's daemon from its other services. */
    private const string APP_LABEL = 'hilos.role: app';

    /** The compose file of a cluster stand, relative to its demo. */
    private const string CLUSTER_COMPOSE = 'docker/docker-compose.cluster.yml';

    /** The file every node of a cluster stand reads, relative to its demo. */
    private const string CLUSTER_ENV = 'docker/cluster/common.env';

    /** Where a registry row of a demo starts. */
    private const string DEMO_ROOT = 'demo/';

    /** The rule a message sends its reader to. */
    private const string RULES = 'docs/agents/testing.md';

    /** A key at the top level of a compose file. */
    private const string TOP_LEVEL_KEY = '/^([A-Za-z0-9_.-]+):/';

    /** A service of the `services` section, indented by exactly two spaces. */
    private const string SERVICE_KEY = '/^ {2}["\']?([A-Za-z0-9_.-]+)["\']?:/';

    /** A stand that lost the line goes back to rotating under a run at night. */
    public function testEveryDemoStandHoldsTheScheduleOutOfReach(): void
    {
        $problems = [];
        foreach ($this->demoStands() as $stand) {
            $path = $this->judgedFile($stand);
            $problem = $this->problemOf($path, $this->linesOf($path));
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
        foreach ([$compose, 'shop-daemon-test', '- LOG_ROTATION_CRON=0 3 29 2 *', 'docs/agents/testing.md'] as $expected) {
            $this->assertStringContainsString($expected, $message);
        }

        $env = 'demo/shop/docker/cluster/common.env';
        $message = $this->problemOf($env, ['APP_ENV=dev', '# LOG_ROTATION_CRON=0 3 29 2 *']);
        $this->assertNotNull($message);
        foreach ([$env, 'LOG_ROTATION_CRON=0 3 29 2 *', 'docs/agents/testing.md'] as $expected) {
            $this->assertStringContainsString($expected, $message);
        }
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
        if (str_ends_with($path, self::CLUSTER_ENV)) {
            return in_array(self::SCHEDULE, $lines, true)
                ? null
                : "{$path}: this cluster stand's nodes rotate their logs at night, under a run. Add the line `"
                    . self::SCHEDULE . '` to this file; see ' . self::RULES;
        }

        foreach ($this->servicesOf($lines) as $service => $body) {
            if (in_array(self::APP_LABEL, $body, true)) {
                return in_array(self::COMPOSE_LINE, $body, true)
                    ? null
                    : "{$path}: the service {$service} rotates its logs at night, under a run. Add the line `"
                        . self::COMPOSE_LINE . '` to its environment; see ' . self::RULES;
            }
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
     * The file a stand is judged by.
     *
     * @param array{cwd: string, composeFile: string} $stand Registry row of the stand
     * @return string Repository-relative path
     */
    private function judgedFile(array $stand): string
    {
        return $stand['cwd'] . '/' . ($stand['composeFile'] === self::CLUSTER_COMPOSE ? self::CLUSTER_ENV : $stand['composeFile']);
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
     * The services of one compose file.
     *
     * @param array<int, string> $lines The lines of the file
     * @return array<string, array<int, string>> Service name => the lines of its body, trimmed
     */
    private function servicesOf(array $lines): array
    {
        $services = [];
        $section = null;
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::TOP_LEVEL_KEY, $line, $matches) === 1) {
                $section = $matches[1];
                $current = null;
            } elseif ($section === 'services' && preg_match(self::SERVICE_KEY, $line, $matches) === 1) {
                $current = $matches[1];
                $services[$current] = [];
            } elseif ($current !== null) {
                $services[$current][] = trim($line);
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

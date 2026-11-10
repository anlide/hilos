<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use PHPUnit\Framework\TestCase;

/**
 * Holds every demo to one source for each of its two env files.
 *
 * The project's own .env is a per-machine file: the framework no longer writes it by itself
 * (a missing file is read as an empty cache and left missing), so `composer run setup-env` is
 * the single way it comes into being, and the step has to name .env.example.
 *
 * The test stack's tests/.env is not per-machine at all: database host, schema and the
 * switched-off throttle are the same on every checkout, and the daemon refuses to start on
 * APP_ENV=test without the file. It is therefore tracked, and nothing copies it. A copy made
 * once by hand never followed its example again: on the line's working trees the copies drifted
 * from the tracked values, and a demo that arrived with the base had none, so its steps went red
 * before a single test ran (pmd, 2026-11-10). Each demo's .gitignore hides `.env` at any depth,
 * so it must re-include tests/.env; without that line `git add` skips the file in silence, the
 * tree it was written in still passes, and only a fresh clone fails.
 *
 * Read as text on purpose: what matters is what the script and the ignore file SAY. A demo is
 * reached through the same glob the neighbouring compose guard uses - a directory under demo/
 * holding a compose stack - so the scaffolded demos that carry a bare Dockerfile and no stack
 * are skipped in silence, along with the case of a framework shipped without the demos at all.
 *
 * @see DemoStackComposeGuardTest for the same shape applied to the compose stacks
 */
final class DemoSetupEnvGuardTest extends TestCase
{
    /** Name of the composer script that is the only way to get the project's .env. */
    private const string SETUP_SCRIPT = 'setup-env';

    /** The example every demo's own .env is copied from. */
    private const string ENV_EXAMPLE = '.env.example';

    /** The tracked env file of the demo's test stack. */
    private const string TEST_ENV = 'tests/.env';

    /** The ignore-file line that keeps the test env tracked under the demo's `.env` rule. */
    private const string TEST_ENV_UNIGNORE = '!tests/.env';

    /**
     * Every containerized demo offers a setup step, it copies the project's example, and it
     * leaves the tracked test env alone.
     */
    public function testEverySetupStepCopiesOnlyTheProjectExample(): void
    {
        $demos = 0;

        foreach ($this->demoDirectories() as $relativeDir => $dir) {
            $demos++;
            $relativePath = $relativeDir . '/composer.json';
            // external-boundary: a file the glob just listed, read as an empty document when unreadable
            $scripts = json_decode((string)file_get_contents($dir . '/composer.json'), true)['scripts'] ?? [];
            $this->assertIsArray($scripts, "{$relativePath}: scripts is not a table");
            $this->assertArrayHasKey(
                self::SETUP_SCRIPT,
                $scripts,
                "{$relativePath}: no " . self::SETUP_SCRIPT . " script, so the demo has no way to get an env file"
            );

            $script = $scripts[self::SETUP_SCRIPT];
            $command = is_array($script) ? implode(' ', $script) : (string)$script;
            // The demo's own example is matched apart from the test path, which ends in the same
            // characters.
            $this->assertSame(
                1,
                preg_match('#(?<!tests/)' . preg_quote(self::ENV_EXAMPLE, '#') . '#', $command),
                "{$relativePath}: " . self::SETUP_SCRIPT . " never names " . self::ENV_EXAMPLE
            );
            $this->assertStringNotContainsString(
                self::TEST_ENV,
                $command,
                "{$relativePath}: " . self::SETUP_SCRIPT . " touches " . self::TEST_ENV . ", which is tracked"
            );
        }

        $this->assertGreaterThan(0, $demos, 'no demo composer.json was found at all - the glob stopped seeing them');
    }

    /**
     * Every containerized demo carries its test env as a tracked file, with no example beside it,
     * and its ignore file re-includes it.
     */
    public function testEveryTestStackEnvIsTracked(): void
    {
        $demos = 0;

        foreach ($this->demoDirectories() as $relativeDir => $dir) {
            $demos++;
            $this->assertFileExists($dir . '/' . self::TEST_ENV, "{$relativeDir}: no " . self::TEST_ENV);
            $this->assertFileDoesNotExist(
                $dir . '/' . self::TEST_ENV . '.example',
                "{$relativeDir}: " . self::TEST_ENV . '.example beside the tracked ' . self::TEST_ENV . ' - one source only'
            );

            $ignore = $dir . '/.gitignore';
            $this->assertFileExists($ignore, "{$relativeDir}: no .gitignore, so the root `.env` rule hides " . self::TEST_ENV);
            // external-boundary: a file just checked to exist, read as empty when unreadable
            $lines = array_map('trim', explode("\n", (string)file_get_contents($ignore)));
            $this->assertContains(
                self::TEST_ENV_UNIGNORE,
                $lines,
                "{$relativeDir}/.gitignore: no `" . self::TEST_ENV_UNIGNORE . '` line, so `git add` skips the test env'
            );
        }

        $this->assertGreaterThan(0, $demos, 'no demo with a compose stack was found at all - the glob stopped seeing them');
    }

    /**
     * Every demo that ships a compose stack and a composer.json, keyed by its path from the
     * repository root.
     *
     * @return array<string, string> Repository-relative directory => absolute directory
     */
    private function demoDirectories(): array
    {
        $root = dirname(__DIR__, 4);
        $dirs = [];

        foreach (glob($root . '/demo/*', GLOB_ONLYDIR) ?: [] as $demo) {
            if (!is_file($demo . '/composer.json') || (glob($demo . '/docker/docker-compose*.yml') ?: []) === []) {
                continue;
            }

            $dirs[substr($demo, strlen($root) + 1)] = $demo;
        }

        return $dirs;
    }
}

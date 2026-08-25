<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Environment;

use PHPUnit\Framework\TestCase;

/**
 * Holds every demo's setup step to naming both env files it is now the only source of.
 *
 * The framework no longer writes .env by itself: a missing file is read as an empty cache and
 * left missing, so `composer run setup-env` is the single way either env file comes into being.
 * A demo whose step copies only .env therefore hands a test stack no tests/.env, and the
 * daemon refuses to start on APP_ENV=test rather than run with the wrong values - a failure
 * that surfaces on someone's fresh checkout and nowhere earlier. That is why it is checked
 * here rather than left to whoever writes the next demo's composer.json.
 *
 * Read as text on purpose: what matters is that the script NAMES both examples, and any
 * shell that copies them says their names. A demo is reached through the same glob the
 * neighbouring compose guard uses - a directory under demo/ holding a compose stack - so the
 * scaffolded demos that carry a bare Dockerfile and no stack are skipped in silence, along
 * with the case of a framework shipped without the demos at all.
 *
 * @see DemoStackComposeGuardTest for the same shape applied to the compose stacks
 */
final class DemoSetupEnvGuardTest extends TestCase
{
    /** Name of the composer script that is the only way to get an env file. */
    private const string SETUP_SCRIPT = 'setup-env';

    /** The example every demo's own .env is copied from. */
    private const string ENV_EXAMPLE = '.env.example';

    /** The example the test stack's env file is copied from. */
    private const string TEST_ENV_EXAMPLE = 'tests/.env.example';

    /**
     * Every containerized demo offers a setup step, and it copies both examples.
     */
    public function testEverySetupStepNamesBothExamples(): void
    {
        $demos = 0;

        foreach ($this->demoComposerFiles() as $relativePath => $path) {
            $demos++;
            // external-boundary: a file the glob just listed, read as an empty document when unreadable
            $scripts = json_decode((string)file_get_contents($path), true)['scripts'] ?? [];
            $this->assertIsArray($scripts, "{$relativePath}: scripts is not a table");
            $this->assertArrayHasKey(
                self::SETUP_SCRIPT,
                $scripts,
                "{$relativePath}: no " . self::SETUP_SCRIPT . " script, so the demo has no way to get an env file"
            );

            $script = $scripts[self::SETUP_SCRIPT];
            $command = is_array($script) ? implode(' ', $script) : (string)$script;
            $this->assertStringContainsString(
                self::TEST_ENV_EXAMPLE,
                $command,
                "{$relativePath}: " . self::SETUP_SCRIPT . " never names " . self::TEST_ENV_EXAMPLE
            );
            // The demo's own example is matched apart from the test one, which ends in the same
            // characters: a step copying tests/.env.example alone would otherwise read as complete.
            $this->assertSame(
                1,
                preg_match('#(?<!tests/)' . preg_quote(self::ENV_EXAMPLE, '#') . '#', $command),
                "{$relativePath}: " . self::SETUP_SCRIPT . " never names " . self::ENV_EXAMPLE
            );
        }

        $this->assertGreaterThan(0, $demos, 'no demo composer.json was found at all - the glob stopped seeing them');
    }

    /**
     * The composer.json of every demo that ships a compose stack, keyed by its path from the
     * repository root.
     *
     * @return array<string, string> Repository-relative path => absolute path
     */
    private function demoComposerFiles(): array
    {
        $root = dirname(__DIR__, 4);
        $files = [];

        foreach (glob($root . '/demo/*', GLOB_ONLYDIR) ?: [] as $demo) {
            $path = $demo . '/composer.json';
            if (!is_file($path) || (glob($demo . '/docker/docker-compose*.yml') ?: []) === []) {
                continue;
            }

            $files[substr($path, strlen($root) + 1)] = $path;
        }

        return $files;
    }
}

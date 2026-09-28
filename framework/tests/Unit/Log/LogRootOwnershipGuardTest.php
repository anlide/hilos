<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\LogRootOwnerMarker;
use Hilos\Log\LogRootOwnershipGuard;
use Hilos\Utils\Exception\LogRootOwnedByAnotherException;
use PHPUnit\Framework\TestCase;

/**
 * The startup claim that this daemon owns the log directory (HIL-1083).
 *
 * Three outcomes: an empty directory receives a marker; a marker that already names
 * this process is refreshed; a marker that names another pair refuses the start and
 * the message names both sides. A directory that is not there yet is created by the
 * claim, which under docker is the watchdog's first touch of it (HIL-1130).
 */
final class LogRootOwnershipGuardTest extends TestCase
{
    /** @var string Environment the arriving process uses unless a case overrides it */
    private const string ENVIRONMENT = 'local';

    /** @var string Node id the arriving process uses unless a case overrides it */
    private const string NODE = '';

    private string $dir = '';

    private ?EnvAccessor $previousEnv = null;

    /** @var array<string, string|false> Process-environment values to put back */
    private array $previousEnvValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-log-root-guard-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }

        $this->previousEnv = Hilos::$env;
        $this->previousEnvValues = [
            EnvConstants::DAEMON_LOG_FILE->name => getenv(EnvConstants::DAEMON_LOG_FILE->name),
            EnvConstants::APP_ENV->name => getenv(EnvConstants::APP_ENV->name),
            EnvConstants::CLUSTER_NODE_ID->name => getenv(EnvConstants::CLUSTER_NODE_ID->name),
        ];
        Hilos::$env = new EnvAccessor();
        $this->putEnv(EnvConstants::DAEMON_LOG_FILE, $this->dir . DIRECTORY_SEPARATOR . 'daemon.log');
        $this->putEnv(EnvConstants::APP_ENV, self::ENVIRONMENT);
        $this->putEnv(EnvConstants::CLUSTER_NODE_ID, self::NODE);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);

        foreach ($this->previousEnvValues as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testAnEmptyDirectoryReceivesAMarker(): void
    {
        LogRootOwnershipGuard::claimLogRoot();

        $owner = LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE);

        $this->assertNotNull($owner);
        $this->assertSame(self::ENVIRONMENT, $owner['environment']);
        $this->assertSame(self::NODE, $owner['node']);
        $this->assertFileExists(LogRootOwnerMarker::pathIn($this->dir));
    }

    public function testAnAbsentDirectoryIsCreatedAndReceivesAMarker(): void
    {
        $absent = $this->dir . DIRECTORY_SEPARATOR . 'absent';
        $this->putEnv(EnvConstants::DAEMON_LOG_FILE, $absent . DIRECTORY_SEPARATOR . 'daemon.log');

        LogRootOwnershipGuard::claimLogRoot();

        $this->assertDirectoryExists($absent);
        $owner = LogRootOwnerMarker::read($absent, self::ENVIRONMENT, self::NODE);

        $this->assertNotNull($owner);
        $this->assertSame(self::ENVIRONMENT, $owner['environment']);
        $this->assertSame(self::NODE, $owner['node']);
    }

    public function testAMatchingMarkerIsRefreshedAndDoesNotRefuse(): void
    {
        file_put_contents(LogRootOwnerMarker::pathIn($this->dir), (string)json_encode([
            'version' => 1,
            'environment' => self::ENVIRONMENT,
            'node' => self::NODE,
            'startedAt' => 1,
        ]));

        LogRootOwnershipGuard::claimLogRoot();

        $owner = LogRootOwnerMarker::read($this->dir, self::ENVIRONMENT, self::NODE);

        $this->assertNotNull($owner);
        $this->assertSame(self::ENVIRONMENT, $owner['environment']);
        $this->assertGreaterThan(1, $owner['startedAt']);
    }

    public function testAForeignPairRefusesAndTheMessageNamesBothEnvironments(): void
    {
        LogRootOwnershipGuard::claimLogRoot();
        $this->putEnv(EnvConstants::APP_ENV, 'test');

        $message = '';
        try {
            LogRootOwnershipGuard::claimLogRoot();
            $this->fail('A log directory owned by another environment was accepted');
        } catch (LogRootOwnedByAnotherException $refusal) {
            $message = $refusal->getMessage();
        }

        $this->assertStringContainsString('local', $message);
        $this->assertStringContainsString('test', $message);
        $this->assertStringContainsString(LogRootOwnerMarker::pathIn($this->dir), $message);
        $this->assertStringContainsString($this->dir, $message);
    }

    /**
     * Recursively removes a directory tree, the marker's dot-file included.
     *
     * @param string $path Directory or file to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }

    /**
     * Writes one cataloged env key into the process environment.
     *
     * @param EnvConstants $key Cataloged env key
     * @param string $value Value to publish
     */
    private function putEnv(EnvConstants $key, string $value): void
    {
        putenv($key->name . '=' . $value);
    }
}

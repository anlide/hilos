<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards that files running only in the watchdog process never write through Logger::errorLog() (HIL-1016).
 *
 * A file that runs only in the watchdog process must not write through Logger::errorLog(),
 * because that method's no-main-log branch reaches the error file alone and the line never
 * appears in docker logs.
 *
 * The test judges source text rather than runtime behavior because what is being defended
 * is a choice of method at a call site, and the only way to see that choice is to read it.
 *
 * Exactly three framework files run in the watchdog process and nowhere else:
 * DockerApplication.php is the entrypoint body of docker.php; DockerManager is instantiated
 * at DockerApplication.php:86 and nowhere else in the repository; OrphanReaper is constructed
 * and reaped at DockerManager.php:384, its only caller. Everything else the watchdog touches —
 * Logger, LogRotator, Migration, DaemonLogAddress — also runs inside the daemon, where a main log
 * file exists and errorLog() is correct there, so judging them would be judging the wrong thing.
 */
final class WatchdogVoiceInContainerLogTest extends TestCase
{
    /** The call whose no-main-log branch reaches the error file alone, never the container log */
    private const string BANNED_CALL = 'Logger::errorLog(';

    /**
     * @return array<string, array{string}>
     */
    public static function watchdogOnlyFiles(): array
    {
        return [
            'framework/backend/Core/Daemon/DockerApplication.php' => ['framework/backend/Core/Daemon/DockerApplication.php'],
            'framework/backend/Core/Daemon/DockerManager.php' => ['framework/backend/Core/Daemon/DockerManager.php'],
            'framework/backend/Core/Daemon/OrphanReaper.php' => ['framework/backend/Core/Daemon/OrphanReaper.php'],
        ];
    }

    #[DataProvider('watchdogOnlyFiles')]
    public function testWatchdogOnlyFileDoesNotWriteThroughErrorLog(string $relativePath): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $content = file_get_contents($repoRoot . '/' . $relativePath);

        $this->assertNotFalse($content);
        $this->assertStringNotContainsString(self::BANNED_CALL, $content);
    }
}

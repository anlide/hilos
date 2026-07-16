<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\BuildTimestampModule;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the build-timestamp daemon module.
 */
final class BuildTimestampModuleTest extends TestCase
{
    /** @var string Temp dist directory created per test */
    private string $distPath;

    /** @var ?string HILOS_BUILD_TIMESTAMP value captured before the test to restore afterwards */
    private ?string $previousTimestamp;

    protected function setUp(): void
    {
        $this->distPath = sys_get_temp_dir() . '/hilos-build-ts-' . uniqid();
        mkdir($this->distPath);

        $captured = getenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name);
        $this->previousTimestamp = $captured === false ? null : $captured;
    }

    protected function tearDown(): void
    {
        $file = $this->distPath . '/build-timestamp.txt';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->distPath)) {
            rmdir($this->distPath);
        }

        if ($this->previousTimestamp === null) {
            putenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name);
        } else {
            putenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name . '=' . $this->previousTimestamp);
        }
    }

    public function testInactiveWhenTimestampFileMissing(): void
    {
        $module = new BuildTimestampModule($this->distPath);

        $this->assertFalse($module->isActive());
    }

    public function testActiveWhenTimestampFilePresent(): void
    {
        file_put_contents($this->distPath . '/build-timestamp.txt', '2026-07-16T12:00:00Z');

        $module = new BuildTimestampModule($this->distPath);

        $this->assertTrue($module->isActive());
    }

    public function testRegisterPublishesTrimmedTimestampToEnv(): void
    {
        file_put_contents($this->distPath . '/build-timestamp.txt', "  2026-07-16T12:00:00Z\n");

        $module = new BuildTimestampModule($this->distPath);
        $module->register($this->createMock(DaemonManager::class), new DaemonContext($this->distPath, $this->distPath));

        $this->assertSame('2026-07-16T12:00:00Z', getenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name));
    }

    public function testRegisterSkipsPublishForEmptyTimestampFile(): void
    {
        putenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name . '=sentinel');
        file_put_contents($this->distPath . '/build-timestamp.txt', "   \n");

        $module = new BuildTimestampModule($this->distPath);
        $module->register($this->createMock(DaemonManager::class), new DaemonContext($this->distPath, $this->distPath));

        $this->assertSame('sentinel', getenv(EnvConstants::HILOS_BUILD_TIMESTAMP->name));
    }
}

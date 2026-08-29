<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the logger write level: which lines the threshold lets through and which
 * it drops, in both line shapes, and the one line that ignores it.
 */
final class LoggerWriteLevelTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(Logger::class);

        foreach (['logFile', 'errorLogFile'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(null, null);
        }

        Logger::setWriteLevel(LogLevel::Info);

        parent::tearDown();
    }

    /**
     * Every threshold writes its own level and everything above it, and drops everything below.
     *
     * @param LogLevel $threshold Level the writer is set to
     * @param array<int, LogLevel> $expectedWritten Levels expected to reach the file, in scale order
     */
    #[DataProvider('writeLevelMatrixProvider')]
    public function testThresholdPassesItsOwnLevelAndEverythingAbove(LogLevel $threshold, array $expectedWritten): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);
        Logger::setWriteLevel($threshold);

        Logger::debug('line-DEBUG');
        Logger::info('line-INFO');
        Logger::warning('line-WARNING');
        Logger::error('line-ERROR');

        $content = file_get_contents($logFile);
        $this->assertNotFalse($content);

        foreach (LogLevel::cases() as $level) {
            $marker = 'line-' . $level->value;
            if (in_array($level, $expectedWritten, true)) {
                $this->assertStringContainsString($marker, $content, "{$level->value} should pass {$threshold->value}");
            } else {
                $this->assertStringNotContainsString($marker, $content, "{$level->value} should not pass {$threshold->value}");
            }
        }
    }

    /**
     * @return array<string, array{LogLevel, array<int, LogLevel>}>
     */
    public static function writeLevelMatrixProvider(): array
    {
        return [
            'DEBUG writes everything' => [
                LogLevel::Debug,
                [LogLevel::Debug, LogLevel::Info, LogLevel::Warning, LogLevel::Error],
            ],
            'INFO drops debug' => [
                LogLevel::Info,
                [LogLevel::Info, LogLevel::Warning, LogLevel::Error],
            ],
            'WARNING keeps warnings and errors' => [
                LogLevel::Warning,
                [LogLevel::Warning, LogLevel::Error],
            ],
            'ERROR keeps errors alone' => [
                LogLevel::Error,
                [LogLevel::Error],
            ],
        ];
    }

    /**
     * An error is written at every threshold: the scale has no step that silences it.
     */
    public function testErrorPassesEveryThreshold(): void
    {
        foreach (LogLevel::cases() as $threshold) {
            $logFile = $this->createTempLogFile();
            Logger::setLogFile($logFile);
            Logger::setWriteLevel($threshold);

            Logger::error('failure');

            $content = file_get_contents($logFile);
            $this->assertNotFalse($content);
            $this->assertStringContainsString('failure', $content, "error dropped at {$threshold->value}");
        }
    }

    /**
     * The agent line shape answers to the same threshold as the plain one.
     */
    public function testAgentLineIsCutByTheSameThreshold(): void
    {
        Logger::setWriteLevel(LogLevel::Warning);

        ob_start();
        Logger::logAgentDebug('agent-1', 'agent-debug');
        Logger::logAgentInfo('agent-1', 'agent-info');
        Logger::logAgentWarning('agent-1', 'agent-warning');
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $this->assertStringNotContainsString('agent-debug', $output);
        $this->assertStringNotContainsString('agent-info', $output);
        $this->assertStringContainsString('agent-warning', $output);
    }

    /**
     * An agent debug line is written once the threshold is lowered to DEBUG.
     */
    public function testAgentDebugLineIsWrittenAtDebugThreshold(): void
    {
        Logger::setWriteLevel(LogLevel::Debug);

        ob_start();
        Logger::logAgentDebug('agent-1', 'agent-debug');
        $output = ob_get_clean();

        $this->assertNotFalse($output);
        $this->assertStringContainsString('agent-debug', $output);
        $this->assertStringContainsString('agent-1|' . Logger::LEVEL_DEBUG . '|', $output);
    }

    /**
     * The write level change line is written even at a threshold that would drop its own level.
     *
     * This is the point of the whole exception: raising the level to ERROR makes the log go
     * quiet, and the line explaining why is itself an INFO one.
     */
    public function testWriteLevelChangeLineBypassesTheThreshold(): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);
        Logger::setWriteLevel(LogLevel::Error);

        Logger::info('ordinary');
        Logger::logWriteLevelChange('Log write level set to ERROR (source: setting)');

        $content = file_get_contents($logFile);
        $this->assertNotFalse($content);
        $this->assertStringNotContainsString('ordinary', $content);
        $this->assertStringContainsString('Log write level set to ERROR (source: setting)', $content);
    }

    /**
     * writeLevel() reports back what setWriteLevel() was last given, and starts at INFO.
     */
    public function testWriteLevelReportsWhatWasSet(): void
    {
        $this->assertSame(LogLevel::Info, Logger::writeLevel());

        Logger::setWriteLevel(LogLevel::Warning);

        $this->assertSame(LogLevel::Warning, Logger::writeLevel());
    }

    private function createTempLogFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hilos-write-level-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, '');

        return $path;
    }
}

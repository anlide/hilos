<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for Logger (log line formatting and agent log output).
 */
final class LoggerTest extends TestCase
{
    private const string TIMESTAMP_PATTERN = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]/';

    /** Same stamp without the start anchor, to count how many a line carries */
    private const string TIMESTAMP_ANYWHERE_PATTERN = '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]/';

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(Logger::class);

        foreach (['logFile', 'errorLogFile'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(null, null);
        }

        foreach (['showLogLevel', 'debugEnabled'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(null, false);
        }

        parent::tearDown();
    }

    /**
     * info() without showLogLevel writes timestamp and message without INFO prefix.
     */
    public function testInfoWithoutShowLogLevelHasNoLevelPrefix(): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);

        Logger::info('hello');

        $line = $this->readLogLine($logFile);
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $line);
        $this->assertSame('[', $line[0]);
        $this->assertStringContainsString(' hello', $line);
        $this->assertStringNotContainsString('[INFO]', $line);
        $this->assertStringNotContainsString('INFO:', $line);
    }

    /**
     * info() with showLogLevel includes [INFO] prefix.
     */
    public function testInfoWithShowLogLevelIncludesBracketedLevel(): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);
        Logger::setShowLogLevel(true);

        Logger::info('hello');

        $line = $this->readLogLine($logFile);
        $this->assertStringContainsString('[INFO]', $line);
        $this->assertStringContainsString(' hello', $line);
    }

    /**
     * error() writes ERROR prefix to main log and plain message to error log.
     */
    public function testErrorWritesToMainAndErrorLogFiles(): void
    {
        $logFile = $this->createTempLogFile();
        $errorLogFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);
        Logger::setErrorLogFile($errorLogFile);

        Logger::error('failure');

        $mainLine = $this->readLogLine($logFile);
        $errorLine = $this->readLogLine($errorLogFile);

        $this->assertStringContainsString('ERROR:', $mainLine);
        $this->assertStringContainsString(' failure', $mainLine);
        $this->assertStringNotContainsString('ERROR:', $errorLine);
        $this->assertStringContainsString(' failure', $errorLine);
    }

    /**
     * error() with only the error log file set writes one stamped line there and keeps
     * echoing to stdout (the docker watchdog setup).
     */
    public function testErrorWritesToErrorLogFileWithoutMainLogFile(): void
    {
        $errorLogFile = $this->createTempLogFile();
        Logger::setErrorLogFile($errorLogFile);

        ob_start();
        Logger::error('failure');
        $stdout = ob_get_clean();

        $content = file_get_contents($errorLogFile);
        $this->assertNotFalse($content);
        $this->assertSame(1, substr_count($content, "\n"));

        $line = rtrim($content, "\n");
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $line);
        $this->assertSame(1, preg_match_all(self::TIMESTAMP_ANYWHERE_PATTERN, $line));
        $this->assertStringNotContainsString('ERROR:', $line);
        $this->assertStringContainsString(' failure', $line);

        $this->assertNotFalse($stdout);
        $this->assertStringContainsString('ERROR:', $stdout);
    }

    /**
     * errorLog() with only the error log file set stamps the message and writes it there
     * instead of falling through to error_log().
     */
    public function testErrorLogWritesToErrorLogFileWithoutMainLogFile(): void
    {
        $errorLogFile = $this->createTempLogFile();
        Logger::setErrorLogFile($errorLogFile);

        Logger::errorLog('raw failure');

        $line = $this->readLogLine($errorLogFile);
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $line);
        $this->assertSame(1, preg_match_all(self::TIMESTAMP_ANYWHERE_PATTERN, $line));
        $this->assertStringContainsString(' raw failure', $line);
    }

    /**
     * debug() does not write when debug logging is disabled.
     */
    public function testDebugDoesNotWriteWhenDisabled(): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);

        Logger::debug('secret');

        $this->assertSame('', file_get_contents($logFile));
    }

    /**
     * debug() writes DEBUG prefix when debug logging is enabled.
     */
    public function testDebugWritesWhenEnabled(): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);
        Logger::setDebugEnabled(true);

        Logger::debug('secret');

        $line = $this->readLogLine($logFile);
        $this->assertStringContainsString('DEBUG:', $line);
        $this->assertStringContainsString(' secret', $line);
    }

    /**
     * info() appends JSON-encoded context to the log line.
     */
    public function testInfoAppendsJsonContext(): void
    {
        $logFile = $this->createTempLogFile();
        Logger::setLogFile($logFile);

        Logger::info('with context', ['key' => 'value']);

        $line = $this->readLogLine($logFile);
        $this->assertStringContainsString('with context', $line);
        $this->assertStringContainsString('{"key":"value"}', $line);
    }

    /**
     * logAgentUserMessage() truncates long messages with TRUNCATION_SUFFIX.
     */
    public function testLogAgentUserMessageTruncatesLongMessage(): void
    {
        ob_start();
        $longMessage = str_repeat('a', 250);

        Logger::logAgentUserMessage('agent-1', 'user-1', $longMessage);

        $line = ob_get_clean();
        $this->assertNotFalse($line);
        $this->assertStringContainsString('...', $line);
        $this->assertSame(1, preg_match('/\[message=(.+)\]$/', trim($line), $matches));
        $this->assertLessThanOrEqual(200, mb_strlen($matches[1]));
    }

    /**
     * logAgentInfo() writes agent log marker, agent id, and level to stdout.
     */
    public function testLogAgentInfoWritesAgentLogFormatToStdout(): void
    {
        ob_start();

        Logger::logAgentInfo('agent-42', 'agent message');

        $line = ob_get_clean();
        $this->assertNotFalse($line);
        $this->assertStringStartsWith(Logger::AGENT_LOG_MARKER, $line);
        $this->assertStringContainsString('agent-42|' . Logger::LEVEL_INFO . '|', $line);
        $this->assertStringContainsString('agent message', $line);
    }

    private function createTempLogFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hilos-logger-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, '');

        return $path;
    }

    private function readLogLine(string $logFile): string
    {
        $content = file_get_contents($logFile);
        $this->assertNotFalse($content);

        return rtrim($content, "\n");
    }
}

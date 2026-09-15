<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\LogStreamConstants;
use Hilos\Log\AgentLogStream;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

final class AgentLogStreamTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hilos-agent-log-test-' . mt_rand();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    /**
     * INFO lands in agent-<id>.log with the level after the stamp, and .error.log is not created.
     */
    public function testAppendInfoWritesToMainStreamWithLevelAfterTimestamp(): void
    {
        AgentLogStream::append(
            $this->dir,
            'agent-1',
            Logger::LEVEL_INFO,
            '[2026-09-14 12:00:00.123] info message',
            false,
        );

        $mainLog = AgentLogStream::pathFor($this->dir, 'agent-1', false);
        $errorLog = AgentLogStream::pathFor($this->dir, 'agent-1', true);

        $this->assertFileExists($mainLog);
        $this->assertFileDoesNotExist($errorLog);

        $content = file_get_contents($mainLog);
        $this->assertNotFalse($content);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] \[INFO\] /', $content);
        $this->assertStringContainsString('info message', $content);
    }

    /**
     * ERROR lands in agent-<id>.error.log as bare [stamp] text without level, and .log is not created.
     */
    public function testAppendErrorWritesToErrorStreamWithoutLevel(): void
    {
        AgentLogStream::append(
            $this->dir,
            'agent-1',
            Logger::LEVEL_ERROR,
            '[2026-09-14 12:00:00.123] error message',
            false,
        );

        $mainLog = AgentLogStream::pathFor($this->dir, 'agent-1', false);
        $errorLog = AgentLogStream::pathFor($this->dir, 'agent-1', true);

        $this->assertFileExists($errorLog);
        $this->assertFileDoesNotExist($mainLog);

        $content = file_get_contents($errorLog);
        $this->assertNotFalse($content);
        $this->assertSame("[2026-09-14 12:00:00.123] error message\n", $content);
    }

    /**
     * WARNING on stderr goes to the error twin, proving the rule is "ERROR or stderr".
     */
    public function testAppendWarningFromErrorStreamWritesToErrorStream(): void
    {
        AgentLogStream::append(
            $this->dir,
            'agent-1',
            Logger::LEVEL_WARNING,
            '[2026-09-14 12:00:00.123] warning on stderr',
            true,
        );

        $mainLog = AgentLogStream::pathFor($this->dir, 'agent-1', false);
        $errorLog = AgentLogStream::pathFor($this->dir, 'agent-1', true);

        $this->assertFileExists($errorLog);
        $this->assertFileDoesNotExist($mainLog);

        $content = file_get_contents($errorLog);
        $this->assertNotFalse($content);
        $this->assertSame("[2026-09-14 12:00:00.123] warning on stderr\n", $content);
    }

    /**
     * Agent ID with characters outside [a-zA-Z0-9_-] is sanitized into filename.
     */
    public function testAppendSanitizesAgentIdInFilename(): void
    {
        AgentLogStream::append(
            $this->dir,
            'chat:room:42',
            Logger::LEVEL_INFO,
            '[2026-09-14 12:00:00.123] message',
            false,
        );

        $expectedFile = $this->dir . '/agent-chat_room_42.log';
        $this->assertFileExists($expectedFile);
        $this->assertSame($expectedFile, AgentLogStream::pathFor($this->dir, 'chat:room:42', false));
    }

    /**
     * pathFor() composes both names from LogStreamConstants.
     */
    public function testPathForComposesFromLogStreamConstants(): void
    {
        $logDir = '/var/log/hilos';
        $agentId = 'my-agent';

        $mainPath = AgentLogStream::pathFor($logDir, $agentId, false);
        $errorPath = AgentLogStream::pathFor($logDir, $agentId, true);

        $expectedMain = $logDir . '/' . LogStreamConstants::AGENT_STREAM_PREFIX . $agentId . LogStreamConstants::STREAM_SUFFIX;
        $expectedError = $logDir . '/' . LogStreamConstants::AGENT_STREAM_PREFIX . $agentId . LogStreamConstants::ERROR_STREAM_SUFFIX;

        $this->assertSame($expectedMain, $mainPath);
        $this->assertSame($expectedError, $errorPath);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}

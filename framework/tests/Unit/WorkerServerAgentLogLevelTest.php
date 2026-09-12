<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\LogStreamConstants;
use Hilos\Log\LogLine;
use Hilos\Log\LogLineReader;
use Hilos\Log\LogReadQuery;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The master writes an agent line's level into `agent-<id>.log`, and only there (HIL-868).
 *
 * The level crosses the worker pipe as a field of its own, and until this leaf the master used it only to pick the
 * file: an agent's WARNING landed in `agent-<id>.log` as a bare `[stamp] text`, which {@see LogLineReader} reads as
 * INFO, so the warnings tab could never see it. Nothing tested the writing of an agent file before. The error twin
 * keeps its line bare - the errors feed takes that file by name and shows its text as written.
 *
 * Drives {@see WorkerServer::processWorkerOutput()} over a throwaway directory, the way the master hands it the
 * drained pipe of a worker.
 */
final class WorkerServerAgentLogLevelTest extends TestCase
{
    private const string AGENT_ID = 'hilos_logs:0';

    /** Basename the master files the agent above under: its id sanitized, `:` to `_`, before the stream suffix. */
    private const string AGENT_BASENAME = 'agent-hilos_logs_0';

    /** Suffix of an agent's main stream, the one the warnings scan reads. */
    private const string MAIN_STREAM_SUFFIX = '.log';

    /** Stamp prefix every agent line carries once {@see Logger::logAgent()} has built it. */
    private const string STAMP = '[2026-09-12 12:00:00.001] ';

    /** Basename of the worker whose pipe is drained; only agent lines are sent, so the file is never written. */
    private const string WORKER_BASENAME = 'worker-regular-1';

    /** Temp log root created per test; removed in {@see tearDown()}. */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('hilos-agent-log-', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testAnAgentWarningIsFoundByTheWarningFilter(): void
    {
        $this->drain([self::pipeLine(Logger::LEVEL_INFO, 'tick'), self::pipeLine(Logger::LEVEL_WARNING, 'disk almost full')], false);

        $page = new LogLineReader($this->dir)->read(
            self::AGENT_BASENAME . self::MAIN_STREAM_SUFFIX,
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, levelFilter: Logger::LEVEL_WARNING),
        );

        $this->assertSame(
            [self::STAMP . '[' . Logger::LEVEL_WARNING . '] disk almost full'],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
            'The warnings scan filters by level, so an agent warning must reach the file carrying it.',
        );
    }

    public function testEveryLevelOfTheMainStreamIsNamedBetweenTheStampAndTheText(): void
    {
        $this->drain(
            [
                self::pipeLine(Logger::LEVEL_DEBUG, 'probe'),
                self::pipeLine(Logger::LEVEL_INFO, 'started'),
                self::pipeLine(Logger::LEVEL_WARNING, 'slow'),
            ],
            false,
        );

        $this->assertSame(
            [
                self::STAMP . '[' . Logger::LEVEL_DEBUG . '] probe',
                self::STAMP . '[' . Logger::LEVEL_INFO . '] started',
                self::STAMP . '[' . Logger::LEVEL_WARNING . '] slow',
            ],
            $this->linesOf(self::MAIN_STREAM_SUFFIX),
            'INFO is named too: going unprefixed is the main logger mode, not a property of an agent file.',
        );
    }

    public function testTheErrorTwinKeepsItsLineAsWritten(): void
    {
        $this->drain([self::pipeLine(Logger::LEVEL_ERROR, 'from stderr')], true);
        $this->drain([self::pipeLine(Logger::LEVEL_ERROR, 'from stdout')], false);

        $this->assertSame(
            [self::STAMP . 'from stderr', self::STAMP . 'from stdout'],
            $this->linesOf(LogStreamConstants::ERROR_STREAM_SUFFIX),
            'The errors feed shows the text of this file as written, so a prefix here would rewrite it.',
        );
        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . self::AGENT_BASENAME . self::MAIN_STREAM_SUFFIX);
    }

    public function testALineWithoutAStampIsWrittenAsItCame(): void
    {
        $this->drain([self::pipeLine(Logger::LEVEL_WARNING, 'no stamp', false)], false);

        $this->assertSame(['no stamp'], $this->linesOf(self::MAIN_STREAM_SUFFIX));
    }

    /**
     * Build one agent line as it crosses the worker pipe: marker, id, level, then the message.
     *
     * @param string $level Level field, a {@see Logger} `LEVEL_*` value
     * @param string $text Text of the message
     * @param bool $stamped Whether the message opens with the stamp {@see Logger::logAgent()} puts there
     *
     * @return string Pipe line without the trailing newline
     */
    private static function pipeLine(string $level, string $text, bool $stamped = true): string
    {
        return Logger::AGENT_LOG_MARKER . self::AGENT_ID . Logger::AGENT_LOG_FIELD_SEPARATOR . $level
            . Logger::AGENT_LOG_FIELD_SEPARATOR . ($stamped ? self::STAMP . $text : $text);
    }

    /**
     * Hand the master the drained pipe of a worker, as it does once a second.
     *
     * @param list<string> $pipeLines Lines of the pipe, without their newlines
     * @param bool $isStderr Whether the pipe is the worker's stderr
     */
    private function drain(array $pipeLines, bool $isStderr): void
    {
        // Skip the constructor: it reads the worker environment and creates a log directory, and the drain needs neither.
        $server = new ReflectionClass(AgentLogLevelTestWorkerServer::class)->newInstanceWithoutConstructor();
        $workerLogFile = $this->dir . DIRECTORY_SEPARATOR . self::WORKER_BASENAME . self::MAIN_STREAM_SUFFIX;

        new ReflectionMethod(WorkerServer::class, 'processWorkerOutput')
            ->invoke($server, implode("\n", $pipeLines) . "\n", $workerLogFile, $this->dir, $isStderr);
    }

    /**
     * Read the lines of one of the agent's two streams.
     *
     * @param string $suffix Stream suffix: {@see MAIN_STREAM_SUFFIX} or {@see LogStreamConstants::ERROR_STREAM_SUFFIX}
     *
     * @return list<string> Lines in file order, without their newlines
     */
    private function linesOf(string $suffix): array
    {
        $lines = file($this->dir . DIRECTORY_SEPARATOR . self::AGENT_BASENAME . $suffix, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines, 'The agent stream must have been written.');

        return $lines;
    }
}

/**
 * Worker server with nothing of its own: the drain of a pipe is base-class code from end to end.
 */
final class AgentLogLevelTestWorkerServer extends WorkerServer
{
    protected function onStart(): void
    {
        // Not used in this test
    }
}

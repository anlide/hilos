<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ErrorException;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The worker's own PHP failure handlers put a stamped head in both captured streams.
 */
final class WorkerManagerFailureLineStampTest extends TestCase
{
    private const string TIMESTAMP_PATTERN = '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]/';

    private const string TIMESTAMP_ANYWHERE_PATTERN = '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]/';

    private string $errorLogFile;

    private string $previousErrorLog;

    private int $previousErrorReporting;

    protected function setUp(): void
    {
        parent::setUp();

        $file = tempnam(sys_get_temp_dir(), 'hilos-worker-failure-');
        self::assertNotFalse($file);
        $this->errorLogFile = $file;

        $previous = ini_get('error_log');
        self::assertNotFalse($previous);
        $this->previousErrorLog = $previous;
        self::assertNotFalse(ini_set('error_log', $file));
        $this->previousErrorReporting = error_reporting();
        error_reporting($this->previousErrorReporting | E_WARNING);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        error_reporting($this->previousErrorReporting);
        unlink($this->errorLogFile);
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAWarningCaughtByTheWorkerHandlerReachesStderrStamped(): void
    {
        $manager = new WorkerManagerFailureLineStampTestManager();

        try {
            $manager->errorHandler(E_WARNING, 'probe warning', '/x/probe.php', 7);
            self::fail('The worker handler must throw the warning as ErrorException.');
        } catch (ErrorException) {
            // The emitted line is the subject of this case.
        }

        $line = $this->errorLine();
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $line);
        $this->assertSame(1, preg_match_all(self::TIMESTAMP_ANYWHERE_PATTERN, $line));
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] WARNING in probe\.php:7 - probe warning$/',
            $line,
        );
    }

    public function testTheSameWarningReachesStdoutWithItsErrorPrefix(): void
    {
        $manager = new WorkerManagerFailureLineStampTestManager();

        ob_start();
        try {
            $manager->errorHandler(E_WARNING, 'probe warning', '/x/probe.php', 7);
        } catch (ErrorException) {
            // The handler is expected to throw after writing the line.
        }
        $stdout = ob_get_clean();

        $this->assertNotFalse($stdout);
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $stdout);
        $this->assertSame(1, preg_match_all(self::TIMESTAMP_ANYWHERE_PATTERN, $stdout));
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] ERROR: WARNING in probe\.php:7 - probe warning\n$/',
            $stdout,
        );
    }

    public function testAnUncaughtExceptionHeadIsStampedAndItsStackFollowsUnstamped(): void
    {
        $manager = new WorkerManagerFailureLineStampTestManager();

        $manager->exceptionHandler(new RuntimeException('probe exception'));

        $lines = explode("\n", $this->errorLine());
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $lines[0]);
        $this->assertSame(1, preg_match_all(self::TIMESTAMP_ANYWHERE_PATTERN, implode("\n", $lines)));
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] UNCAUGHT EXCEPTION: RuntimeException in .+ - probe exception$/',
            $lines[0],
        );
        $this->assertSame('Stack trace:', $lines[1]);
    }

    public function testAFatalShutdownLineIsStamped(): void
    {
        $manager = new WorkerManagerFailureLineStampTestManager();

        $manager->shutdownLine('FATAL SHUTDOWN: FATAL in probe.php:9 - probe fatal');

        $line = $this->errorLine();
        $this->assertMatchesRegularExpression(self::TIMESTAMP_PATTERN, $line);
        $this->assertSame(1, preg_match_all(self::TIMESTAMP_ANYWHERE_PATTERN, $line));
        $this->assertStringContainsString(' FATAL SHUTDOWN: FATAL in probe.php:9 - probe fatal', $line);
    }

    /**
     * Read the PHP error_log entry after PHP's own date prefix.
     *
     * @return string Worker failure line, without PHP's prefix
     */
    private function errorLine(): string
    {
        $content = file_get_contents($this->errorLogFile);
        self::assertNotFalse($content);
        self::assertMatchesRegularExpression('/^\[[^\]]+\] /', $content);

        return (string)preg_replace('/^\[[^\]]+\] /', '', rtrim($content, "\n"), 1);
    }
}

final class WorkerManagerFailureLineStampTestManager extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Exposes the protected shutdown logger without requiring a fatal in PHPUnit.
     *
     * @param string $message Fatal line to file
     */
    public function shutdownLine(string $message): void
    {
        $this->logShutdown($message);
    }

    /**
     * @return SignalRouter Empty router for the fixture
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Empty agent manager for the fixture
     */
    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerFailureLineStampTestAgentManager();
    }
}

final class WorkerManagerFailureLineStampTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     * @throws LogicException This fixture never creates agents
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new LogicException('No agent belongs to this fixture.');
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\LogStoreAgent;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rotation trigger the log store owner took over (HIL-480).
 *
 * The trigger is driven the way the tick drives it — a walk, then the check that rides it — rather
 * than through {@see LogStoreAgent::onTick()}, whose intervals are wall-clock seconds. The store
 * underneath is a throwaway temp directory, the fixture shape borrowed from
 * {@see LogStoreAgentIndexTest}, and the policy comes from the process environment, which is where
 * the resolver lands when no settings are initialized.
 *
 * What the run cannot cover is named in the leaf and here: the device gate refusing. Two devices
 * cannot be arranged inside a unit test, so only the passing verdict is asserted below and the
 * refusal is exercised by hand on a stand with a bind mount over `archive/`.
 */
final class LogStoreAgentRotationTest extends TestCase
{
    /** Basename of the daemon's main log, as `DAEMON_LOG_FILE` names it in every demo. */
    private const string DAEMON_LOG = 'daemon.log';

    /** Raw stdout beside it, the file runtime rotation must leave where it is. */
    private const string DAEMON_RAW_LOG = 'daemon-raw.log';

    /** Raw stderr beside the error log, kept for the same reason. */
    private const string DAEMON_ERROR_RAW_LOG = 'daemon-error-raw.log';

    /** Any file the rotation is free to carry off. */
    private const string AGENT_LOG = 'agent-hilos_logs.log';

    /** Size axis the tests arm, small enough that one fixture file crosses it. */
    private const string MAX_LIVE_SIZE_BYTES = '1024';

    /** @var int Baseline the walks are placed relative to; the real clock, as onStart stamps its own walk with it */
    private int $t0 = 0;

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->t0 = time();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-rotation-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it rotates, and a
        // journal written there would be one more file for the batch to carry off.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logstore-rotation-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/' . self::DAEMON_LOG);
        putenv(EnvConstants::DAEMON_ERROR_LOG_FILE->name . '=' . $this->dir . '/daemon-error.log');
        // Every axis off unless a test arms one, so nothing rotates behind the case under test.
        $this->putRotationEnvironment('0', '0', '');
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        foreach ([
            EnvConstants::DAEMON_LOG_FILE,
            EnvConstants::DAEMON_ERROR_LOG_FILE,
            EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS,
            EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES,
            EnvConstants::LOG_ROTATION_CRON,
        ] as $key) {
            putenv($key->name);
        }
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->removeTree($this->dir);
    }

    public function testTheSizeAxisLeavesTheDaemonRawStreamsOut(): void
    {
        $this->putRotationEnvironment('0', self::MAX_LIVE_SIZE_BYTES, '');
        $this->write(self::DAEMON_RAW_LOG, 4096);
        $agent = $this->startedAgent();

        $this->walkAndRotate($agent, $this->t0 + 10);

        // Four kibibytes of raw output and not one rotation: counted in, they would hold the axis
        // over forever, and no rotation could ever bring the number back down.
        $this->assertSame([], $this->batchNames());

        $this->write(self::AGENT_LOG, 2048);
        $this->walkAndRotate($agent, $this->t0 + 20);

        $this->assertCount(1, $this->batchNames());
    }

    public function testRotationCarriesOffTheLiveLogsAndLeavesTheRawPair(): void
    {
        $this->putRotationEnvironment('0', self::MAX_LIVE_SIZE_BYTES, '');
        $this->write(self::DAEMON_LOG, 2048);
        $this->write(self::AGENT_LOG, 100);
        $this->write(self::DAEMON_RAW_LOG, 30);
        $this->write(self::DAEMON_ERROR_RAW_LOG, 40);
        $agent = $this->startedAgent();

        $this->walkAndRotate($agent, $this->t0 + 10);

        $this->assertSame([self::DAEMON_ERROR_RAW_LOG, self::DAEMON_RAW_LOG], $this->liveNames());
        $batches = $this->batchNames();
        $this->assertCount(1, $batches);
        $this->assertSame([self::AGENT_LOG, self::DAEMON_LOG], $this->batchFileNames($batches[0]));
    }

    public function testTheNewBatchIsInTheIndexBeforeTheNextFullWalkIsDue(): void
    {
        $this->putRotationEnvironment('0', self::MAX_LIVE_SIZE_BYTES, '');
        $this->write(self::DAEMON_LOG, 2048);
        $agent = $this->startedAgent();

        $this->walkAndRotate($agent, $this->t0 + 10);

        // The full walk is a minute apart and the frame an interval apart; a batch made this
        // instant may wait for neither.
        $delta = $agent->lastDelta();
        $this->assertNotNull($delta);
        $this->assertCount(1, $delta->appearedBatchTimestamps);
    }

    public function testAnExistingArchiveOnTheSameDeviceOpensTheGate(): void
    {
        $this->putRotationEnvironment('0', self::MAX_LIVE_SIZE_BYTES, '');
        // The other half of the gate — an archive that does not exist yet — is what every other
        // case here goes through; this one has the directory already made.
        mkdir($this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME, 0755, true);
        $this->write(self::DAEMON_LOG, 2048);
        $agent = $this->startedAgent();

        $this->walkAndRotate($agent, $this->t0 + 10);

        $this->assertCount(1, $this->batchNames());
    }

    /**
     * The archive path is taken by a file, so the batch cannot be made and the rotator raises.
     *
     * The failing `mkdir()` warns before it returns false, and the assertion is about what the
     * agent does after the exception — hence the error handler is stood down for this test alone.
     */
    #[WithoutErrorHandler]
    public function testABatchThatCannotBeMadeLeavesTheAgentRunning(): void
    {
        $this->putRotationEnvironment('0', self::MAX_LIVE_SIZE_BYTES, '');
        $this->write(self::DAEMON_LOG, 2048);
        $agent = $this->startedAgent();
        file_put_contents(
            $this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME,
            'a file where the archive directory would go',
        );

        $this->walkAndRotate($agent, $this->t0 + 10);

        // Best-effort: the live file is still live, and the agent is still answering.
        $this->assertSame([self::DAEMON_LOG], $this->liveNames());
        $agent->walkStore($this->t0 + 20);
        $this->assertNotNull($agent->lastDelta());
    }

    public function testAGrownRawStreamIsComplainedAboutOnce(): void
    {
        $agent = $this->startedAgent();
        // Made after the start so the first complaint falls inside the captured output below.
        $this->writeSparse(self::DAEMON_RAW_LOG, 16 * 1024 * 1024);

        ob_start();
        $agent->walkStore($this->t0 + 10);
        $agent->walkStore($this->t0 + 20);
        $said = (string)ob_get_clean();

        $this->assertSame(
            1,
            substr_count($said, 'The daemon raw output ' . self::DAEMON_RAW_LOG . ' has grown to 16 MiB'),
        );
    }

    /**
     * Agent over the fixture directory, walked once by its own start hook.
     *
     * @return LogStoreAgent Started agent
     */
    private function startedAgent(): LogStoreAgent
    {
        $agent = new LogStoreAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * Drives one tick's worth of the trigger: the walk, then the check that rides it.
     *
     * @param LogStoreAgent $agent Agent under test
     * @param int $now Instant to stamp the walk with
     */
    private function walkAndRotate(LogStoreAgent $agent, int $now): void
    {
        $agent->walkStore($now);
        $agent->rotateIfDue((float)$now);
    }

    /**
     * Writes all three rotation keys into the process environment, which the policy reads.
     *
     * @param string $maxAge Raw value for the age axis
     * @param string $maxSize Raw value for the size axis
     * @param string $cron Raw value for the schedule axis
     */
    private function putRotationEnvironment(string $maxAge, string $maxSize, string $cron): void
    {
        putenv(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS->name . '=' . $maxAge);
        putenv(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES->name . '=' . $maxSize);
        putenv(EnvConstants::LOG_ROTATION_CRON->name . '=' . $cron);
    }

    /**
     * @param string $name Basename under the fixture directory
     * @param int $bytes Size to fill it to
     */
    private function write(string $name, int $bytes): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, str_repeat('x', $bytes));
    }

    /**
     * Makes a file of the given size without writing its bytes, for the sizes worth mebibytes.
     *
     * @param string $name Basename under the fixture directory
     * @param int $bytes Size the file reports
     */
    private function writeSparse(string $name, int $bytes): void
    {
        $handle = fopen($this->dir . DIRECTORY_SEPARATOR . $name, 'wb');
        if ($handle === false) {
            $this->fail("Could not create fixture file: {$name}");
        }
        ftruncate($handle, $bytes);
        fclose($handle);
    }

    /**
     * @return list<string> Basenames of the live *.log files, sorted
     */
    private function liveNames(): array
    {
        return $this->sortedBasenames($this->dir);
    }

    /**
     * @return list<string> Names of the batch directories under the archive, sorted
     */
    private function batchNames(): array
    {
        $batches = glob($this->dir . '/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/*', GLOB_ONLYDIR);
        if ($batches === false) {
            return [];
        }
        $names = array_map(basename(...), $batches);
        sort($names);

        return $names;
    }

    /**
     * @param string $batchName Name of one batch directory
     * @return list<string> Basenames of the log files that batch holds, sorted
     */
    private function batchFileNames(string $batchName): array
    {
        return $this->sortedBasenames(
            $this->dir . '/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/' . $batchName,
        );
    }

    /**
     * @param string $directory Directory to list the *.log files of
     * @return list<string> Basenames, sorted
     */
    private function sortedBasenames(string $directory): array
    {
        $files = glob($directory . '/*.log');
        if ($files === false) {
            return [];
        }
        $names = array_map(basename(...), $files);
        sort($names);

        return $names;
    }

    /**
     * Recursively removes a directory tree.
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
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\DaemonHangMonitor;
use Hilos\Core\Daemon\DaemonOutputQuote;
use Hilos\Core\Daemon\DockerManager;
use Hilos\Core\Daemon\MasterHeartbeat;
use Hilos\Core\Process;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** Actual watchdog decisions over a fake Process; the stand owns the two-process recovery proof. */
final class DockerManagerHeartbeatTest extends TestCase
{
    private DockerManager $manager;
    private HeartbeatChild $child;
    private DaemonHangMonitor $monitor;
    private ?EnvAccessor $previousEnv;
    /** @var array<string, string|false> Process environment restored after each case */
    private array $previousValues = [];
    private string $logFile;
    /** @var resource Reader owned by the fixture */
    private mixed $reader;
    /** @var resource Writer used for immediately available bytes and EOF */
    private mixed $writer;

    public function testControlAndObservedExitTakePriorityAndBoundaryMarkRefreshesSilence(): void
    {
        $this->monitor->markReceived($this->now() - 70.0);
        fwrite($this->writer, MasterHeartbeat::MARK);
        $this->invoke('tickDaemon');
        self::assertSame([], $this->child->terms);
        self::assertFalse($this->monitor->isStale($this->now()));
        self::assertSame(1, $this->child->observations);

        $this->monitor->bringStaleToNow($this->now());
        $this->manager->handleShutdown();
        $this->invoke('tickDaemon');
        self::assertCount(1, $this->child->terms, 'ordinary stop sends TERM using the current snapshot');
        self::assertSame($this->child->snapshot(), $this->child->terms[0]);
        self::assertNull($this->monitor->killAt());
        self::assertStringNotContainsString('main loop stalled', file_get_contents($this->logFile));

        $this->set('shouldExit', false);
        $this->monitor->reset();
        $this->monitor->markReceived($this->now() - 70.0);
        $this->child->running = false;
        $this->invoke('tickDaemon');
        self::assertCount(1, $this->child->terms, 'an observed exit cannot become a hang incident');
        self::assertStringNotContainsString('main loop stalled', file_get_contents($this->logFile));
    }

    public function testHangGraceStartsAtTheTermRequestAfterDiagnostics(): void
    {
        $observedAt = $this->now() - 6.0;
        $this->monitor->markReceived($observedAt - 70.0);
        $status = $this->child->observe();
        $this->invoke('advanceHangStop', $status, $observedAt);
        $requestedAt = $this->child->stopRequestedAt;
        self::assertNotNull($requestedAt);
        $deadline = $this->monitor->killAt();
        self::assertNotNull($deadline);
        self::assertGreaterThan($observedAt + 5.0, $requestedAt, 'the stale observation predates the actual TERM');
        self::assertGreaterThanOrEqual(
            $requestedAt + 5.0,
            $deadline,
            'tail reading and logging before TERM must not consume any of its five-second grace',
        );
        self::assertLessThanOrEqual($this->now() + 5.0, $deadline);

        $this->invoke('advanceHangStop', $status, $requestedAt + 4.999);
        self::assertCount(1, $this->child->terms);
        self::assertSame([], $this->child->kills, 'the full grace after TERM must elapse before KILL');
        $this->invoke('advanceHangStop', $status, $deadline);
        $this->invoke('advanceHangStop', $status, $deadline);
        self::assertSame([$status], $this->child->kills);
        self::assertCount(1, $this->child->terms);
    }

    public function testHangSendsTermThenOneKillRetainsLiveChildAndQuotesOnlyTheOriginalTail(): void
    {
        $this->set('outputQuote', DaemonOutputQuote::markedAt(null, $this->logFile));
        file_put_contents($this->logFile, "original-child-output\n", FILE_APPEND);
        $this->monitor->markReceived($this->now() - 70.0);
        $this->invoke('tickDaemon');
        $deadline = $this->monitor->killAt();
        self::assertNotNull($deadline);
        self::assertCount(1, $this->child->terms);
        self::assertSame($this->child->snapshot(), $this->child->terms[0]);
        self::assertFalse($this->get('shouldRestart'));
        self::assertNull($this->get('lastErrorRestartTime'), 'backoff begins only on observed exit');
        self::assertGreaterThan(0.0, $this->invoke('nextWaitSeconds'));
        self::assertLessThanOrEqual(5.0, $this->invoke('nextWaitSeconds'));

        fwrite($this->writer, MasterHeartbeat::MARK);
        $this->invoke('tickDaemon');
        self::assertSame($deadline, $this->monitor->killAt());
        self::assertCount(1, $this->child->terms);
        $this->monitor->bringStopToNow($this->now());
        $this->invoke('tickDaemon');
        $this->invoke('tickDaemon');
        self::assertCount(1, $this->child->kills);
        self::assertSame($this->child->snapshot(), $this->child->kills[0]);
        self::assertSame($this->child, $this->get('process'), 'SIGKILL is not proof of exit');
        self::assertNull($this->monitor->killAt());
        self::assertSame(60.0, $this->invoke('nextWaitSeconds'), 'no expired grace remains runnable');

        $this->child->running = false;
        $this->child->termSignal = SIGKILL;
        $this->invoke('tickDaemon');
        $log = file_get_contents($this->logFile);
        self::assertSame(1, substr_count($log, 'Daemon main loop stalled:'));
        self::assertSame(1, substr_count($log, 'sending SIGKILL'));
        self::assertSame(2, substr_count($log, 'original-child-output'), 'original line and its sole incident quote');
        self::assertStringContainsString('after main-loop hang: killed by signal 9', $log);
        self::assertSame(5, $this->child->observations);
    }

    public function testExitStartsNormalErrorBackoffAndClearsEveryChildFact(): void
    {
        $this->monitor->markReceived($this->now() - 70.0);
        $this->invoke('tickDaemon');
        $beforeExit = $this->now();
        $this->child->running = false;
        $this->invoke('tickDaemon');
        self::assertGreaterThanOrEqual($beforeExit, $this->get('lastErrorRestartTime'));
        self::assertFalse($this->invoke('shouldStartDaemon'));
        self::assertFalse($this->get('shouldRestart'));
        self::assertSame(1, $this->get('consecutiveFailedStarts'));
        self::assertNull($this->get('process'));
        self::assertNull($this->get('hangMonitor'));
        self::assertNull($this->get('hangOutputTail'));
        self::assertFalse($this->get('heartbeatReadEnded'));
        self::assertFalse($this->get('daemonStopRequested'));
        $this->set('lastErrorRestartTime', $this->now() - 21.0);
        self::assertTrue($this->invoke('shouldStartDaemon'));
    }

    public function testEveryTestRequestChecksTheCurrentEnvironmentAndPhase(): void
    {
        $this->monitor->markReceived($this->now());
        $original = $this->monitor->staleAt();
        foreach ([AppEnv::PROD->value, AppEnv::STAGING->value, 'unknown', ''] as $environment) {
            putenv(EnvConstants::APP_ENV->name . '=' . $environment);
            $this->invoke('applyHeartbeatTestRequest', SIGUSR1);
            self::assertSame($original, $this->monitor->staleAt());
        }
        Hilos::$env = null;
        $this->invoke('applyHeartbeatTestRequest', SIGUSR1);
        self::assertSame($original, $this->monitor->staleAt());
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::APP_ENV->name . '=' . AppEnv::DEV->value);
        $this->invoke('applyHeartbeatTestRequest', SIGUSR2);
        self::assertNull($this->monitor->killAt());
        $this->invoke('applyHeartbeatTestRequest', SIGUSR1);
        self::assertTrue($this->monitor->isStale($this->now()));
        $this->invoke('tickDaemon');
        $grace = $this->monitor->killAt();
        self::assertNotNull($grace);
        putenv(EnvConstants::APP_ENV->name . '=' . AppEnv::PROD->value);
        $this->invoke('applyHeartbeatTestRequest', SIGUSR2);
        self::assertSame($grace, $this->monitor->killAt());
        putenv(EnvConstants::APP_ENV->name . '=' . AppEnv::DEV->value);
        $this->invoke('applyHeartbeatTestRequest', SIGUSR2);
        $this->invoke('tickDaemon');
        $this->invoke('applyHeartbeatTestRequest', SIGUSR2);
        $this->invoke('tickDaemon');
        self::assertCount(1, $this->child->terms);
        self::assertCount(1, $this->child->kills);
    }

    public function testEofDoesNotMeanDeathAndRequestsDoNotCarryIntoTheNextChild(): void
    {
        $this->invoke('applyHeartbeatTestRequest', SIGUSR1);
        $this->invoke('applyHeartbeatTestRequest', SIGUSR2);
        fwrite($this->writer, MasterHeartbeat::MARK);
        fclose($this->writer);
        $this->invoke('tickDaemon');
        self::assertTrue($this->get('heartbeatReadEnded'));
        self::assertSame($this->child, $this->get('process'));
        self::assertNotNull($this->monitor->staleAt());
        self::assertSame([], $this->child->terms);
        $this->monitor->bringStaleToNow($this->now());
        $this->invoke('tickDaemon');
        self::assertCount(1, $this->child->terms, 'silence remains armed after EOF');
        $this->manager->handleShutdown();
        $this->monitor->bringStopToNow($this->now());
        $this->invoke('tickDaemon');
        self::assertCount(1, $this->child->terms, 'terminal stop cannot duplicate the hang TERM');
        self::assertCount(1, $this->child->kills, 'terminal stop still finishes the begun grace');
        $this->child->running = false;
        $this->invoke('tickDaemon');
        self::assertFalse($this->invoke('shouldStartDaemon'));
        $this->invoke('applyHeartbeatTestRequest', SIGUSR1);
        $this->invoke('applyHeartbeatTestRequest', SIGUSR2);
        self::assertNull($this->get('hangMonitor'));
        self::assertNull($this->get('hangOutputTail'));
    }

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        foreach ([EnvConstants::APP_ENV, EnvConstants::DAEMON_MIN_RESTART_INTERVAL, EnvConstants::DAEMON_FAILED_START_THRESHOLD] as $key) {
            $this->previousValues[$key->name] = getenv($key->name);
        }
        putenv(EnvConstants::APP_ENV->name . '=' . AppEnv::DEV->value);
        putenv(EnvConstants::DAEMON_MIN_RESTART_INTERVAL->name . '=20');
        putenv(EnvConstants::DAEMON_FAILED_START_THRESHOLD->name . '=3');
        Hilos::$env = new EnvAccessor();
        $this->logFile = tempnam(sys_get_temp_dir(), 'watchdog-heartbeat-');
        Logger::setLogFile($this->logFile);
        $this->manager = new DockerManager();
        [$this->reader, $this->writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($this->reader, false);
        $this->child = new HeartbeatChild($this->reader);
        $this->monitor = new DaemonHangMonitor();
        $this->set('process', $this->child);
        $this->set('hangMonitor', $this->monitor);
        $this->set('processStartTime', $this->now());
    }

    protected function tearDown(): void
    {
        foreach ([$this->reader, $this->writer] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        Logger::resetLogFile();
        unlink($this->logFile);
        Hilos::$env = $this->previousEnv;
        foreach ($this->previousValues as $key => $value) {
            putenv($key . ($value === false ? '' : '=' . $value));
        }
        parent::tearDown();
    }

    /** @return float Current local monotonic seconds; no production duration is waited out */
    private function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * @param string $property Child-local fact or lifecycle intent
     * @param mixed $value Fixture value
     */
    private function set(string $property, mixed $value): void
    {
        new ReflectionProperty(DockerManager::class, $property)->setValue($this->manager, $value);
    }

    /**
     * @param string $property Child-local fact or lifecycle result
     * @return mixed Actual value
     */
    private function get(string $property): mixed
    {
        return new ReflectionProperty(DockerManager::class, $property)->getValue($this->manager);
    }

    /**
     * @param string $method Actual watchdog phase
     * @param mixed ...$arguments Phase arguments
     * @return mixed Phase result
     */
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return new ReflectionMethod(DockerManager::class, $method)->invoke($this->manager, ...$arguments);
    }
}

final class HeartbeatChild extends Process
{
    public bool $running = true;
    public int $observations = 0;
    public ?int $termSignal = null;
    public ?float $stopRequestedAt = null;
    /** @var list<array<string, mixed>> Snapshots used for TERM, without any second status read */
    public array $terms = [];
    /** @var list<array<string, mixed>> Snapshots used for KILL; fake stays alive until observed exit */
    public array $kills = [];

    /** @param resource $pipe Local heartbeat reader */
    public function __construct(private mixed $pipe)
    {
    }

    /** @return array<string, mixed> Current fake native status */
    public function snapshot(): array
    {
        return [self::STATUS_RUNNING => $this->running];
    }

    /** @return array<string, mixed> One counted observation per pass */
    public function observe(): array
    {
        ++$this->observations;
        return $this->snapshot();
    }

    /**
     * @param int $descriptor Descriptor requested by the watchdog
     * @return resource|null Fixture heartbeat reader only
     */
    public function getPipe(int $descriptor): mixed
    {
        return $descriptor === MasterHeartbeat::DESCRIPTOR ? $this->pipe : null;
    }

    /** @param array<string, mixed> $status Snapshot supplied by the watchdog */
    public function stopFromStatus(array $status): void
    {
        $this->stopRequestedAt = hrtime(true) / 1e9;
        $this->terms[] = $status;
    }

    /** @param array<string, mixed> $status Snapshot supplied by the watchdog */
    public function killFromStatus(array $status): void
    {
        $this->kills[] = $status;
    }

    /** @return ?int Fake normal exit status */
    public function getExitCode(): ?int
    {
        return $this->termSignal === null ? 1 : null;
    }

    /** @return ?int Fake captured terminating signal */
    public function getTermSignal(): ?int
    {
        return $this->termSignal;
    }

    /** No OS process belongs to this fixture. */
    public function __destruct()
    {
    }
}

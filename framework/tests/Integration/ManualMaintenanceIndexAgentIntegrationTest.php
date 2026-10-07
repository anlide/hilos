<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\ManualMaintenanceOutcome;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeFreezeStore;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\StandaloneProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Drives the index agent's queued requests through the real single-node switch and RT actions.
 */
final class ManualMaintenanceIndexAgentIntegrationTest extends TestCase
{
    private string $logDirectory = '';

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    private IntegrationManualIndexAgent $agent;

    private StandaloneProtectedMode $mode;

    protected function setUp(): void
    {
        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'hilos-manual-index');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->logDirectory . '/daemon.log');

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new IntegrationManualRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);

        $this->agent = new IntegrationManualIndexAgent();
        Hilos::$cluster->registerProtectedModeInitiatorRelay(new IntegrationManualRelay($this->agent));
        $this->mode = new StandaloneProtectedMode(new DaemonProtectedModeExecutor());
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::DAEMON_LOG_FILE->name);

        foreach ((array)glob($this->logDirectory . '/*') as $leftover) {
            unlink((string)$leftover);
        }
        rmdir($this->logDirectory);

        parent::tearDown();
    }

    public function testBrowserEntryPassAndExitFollowTheQueuedSignalAndRuntimeRound(): void
    {
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $this->agent->enable('browser-key', 'session-hash', $reply);
        $enable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->assertSame([], $outcomes);

        $this->mode->requestEnable($enable);
        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertSame(ProtectedModeRuntime::PHASE_VERIFYING, $row?->phase);
        $this->assertSame(ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, $row?->entryMode);
        $this->assertSame(ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $row?->operation);
        $this->assertSame('browser-key', $row?->initiatorAcceptKey);
        $this->assertSame('session-hash', $row?->initiatorSessionTokenHash);
        $this->assertSame(HilosAgentType::HILOS_INDEX, $row?->initiatorAgentType);
        $this->assertSame(ManualMaintenanceOutcome::SUCCEEDED, $outcomes[0]->status);
        $this->assertFileExists($this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME);

        $this->agent->mint($reply);
        $pass = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $pass);
        $this->assertCount(1, $outcomes);
        $this->mode->requestPass($pass);
        $this->agent->onTick();
        $this->assertSame(ManualMaintenanceOutcome::SUCCEEDED, $outcomes[1]->status);
        $this->assertSame(
            hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, (string)$outcomes[1]->pass),
            $row?->passHashes[0],
        );

        $this->agent->disable($reply);
        $disable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_DISABLE);
        $this->assertInstanceOf(ProtectedModeDisableSignalData::class, $disable);
        $this->assertCount(2, $outcomes);
        $this->mode->requestDisable($disable);
        $this->agent->onTick();
        $this->assertSame(ProtectedModeRuntime::PHASE_INACTIVE, $row?->phase);
        $this->assertSame(ManualMaintenanceOutcome::SUCCEEDED, $outcomes[2]->status);
        $this->assertFileDoesNotExist($this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME);
    }

    public function testCliEntryRecordsNoBrowserAndRepeatIsRefusedBeforeTheCore(): void
    {
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $this->agent->enable('', null, $reply);
        $enable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->mode->requestEnable($enable);

        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertSame('', $row?->initiatorAcceptKey);
        $this->assertNull($row?->initiatorSessionTokenHash);

        $this->agent->enable('', null, $reply);
        $this->assertSame(ManualMaintenanceOutcome::REFUSED, $outcomes[1]->status);
        $this->assertStringContainsString('already active', (string)$outcomes[1]->reason);
        $this->assertNull($this->nextRequest(SignalTypeConstants::PROTECTED_MODE_ENABLE));
    }

    /**
     * @param string $type Queued signal type to consume
     * @return mixed First matching payload, or null
     */
    private function nextRequest(string $type): mixed
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === $type) {
                return $signal->data;
            }
        }

        return null;
    }
}

/** Index agent exposed to the integration test's transport adapter. */
final class IntegrationManualIndexAgent extends AbstractHilosIndexAgent
{
    /**
     * @param ?string $agentIndex Agent index, null for the singleton
     */
    public function __construct(?string $agentIndex = null)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $acceptKey Browser accept key, empty for CLI
     * @param ?string $sessionHash Browser session hash, null for CLI
     * @param Closure(ManualMaintenanceOutcome):void $reply Result receiver
     */
    public function enable(string $acceptKey, ?string $sessionHash, Closure $reply): void
    {
        $this->enableManualMaintenance($acceptKey, $sessionHash, $reply);
    }

    /**
     * @param Closure(ManualMaintenanceOutcome):void $reply Result receiver
     */
    public function disable(Closure $reply): void
    {
        $this->disableManualMaintenance($reply);
    }

    /**
     * @param Closure(ManualMaintenanceOutcome):void $reply Result receiver
     */
    public function mint(Closure $reply): void
    {
        $this->mintManualMaintenancePass($reply);
    }
}

/** Runtime context with the framework protected-mode singleton mounted. */
final class IntegrationManualRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

/** Delivers a real switch's ready relay to its index-agent initiator. */
final readonly class IntegrationManualRelay implements ProtectedModeInitiatorRelay
{
    public function __construct(private IntegrationManualIndexAgent $agent)
    {
    }

    public function deliverProtectedModeReady(string $agentType, ?string $agentIndex): void
    {
        $this->agent->onProtectedModeReady();
    }

    public function deliverProtectedModeRefused(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->agent->onProtectedModeRefused($reason);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\Exception\ProtectedModeFreezeUnreadableException;
use Hilos\ProtectedMode\ManualMaintenanceOutcome;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\ProtectedMode\ProtectedModeFreezeStore;
use Hilos\ProtectedMode\ProtectedModeInitiatorRelay;
use Hilos\ProtectedMode\StandaloneProtectedMode;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
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

    public function testCommandChannelExecutesLiveSingleServerCycle(): void
    {
        $this->agent->onSignalCommand(new CommandRequestDTO('cli-enable', CliCommands::MAINTENANCE_ENABLE, []), '', '');
        $enable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->mode->requestEnable($enable);

        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertSame(ProtectedModeRuntime::PHASE_VERIFYING, $row?->phase);
        $this->assertSame(ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $row?->operation);
        $this->assertSame('', $row?->initiatorAcceptKey);
        $this->assertNull($row?->initiatorSessionTokenHash);

        $reply1 = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $reply1);
        $this->assertTrue($reply1->isOk());
        $this->assertSame('cli-enable', $reply1->correlationId);
        $this->assertSame(['phase' => 'verifying'], $reply1->payload);

        $this->agent->onSignalCommand(new CommandRequestDTO('cli-pass', CliCommands::MAINTENANCE_PASS, []), '', '');
        $pass = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $pass);
        $this->mode->requestPass($pass);
        $this->agent->onTick();

        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertContains($pass->passHash, $row?->passHashes);

        $reply2 = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $reply2);
        $this->assertTrue($reply2->isOk());
        $this->assertSame('cli-pass', $reply2->correlationId);
        $this->assertSame('verifying', $reply2->payload['phase']);
        $this->assertSame(
            $pass->passHash,
            hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, (string)$reply2->payload['pass']),
        );

        $this->agent->onSignalCommand(new CommandRequestDTO('cli-disable', CliCommands::MAINTENANCE_DISABLE, []), '', '');
        $disable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_DISABLE);
        $this->assertInstanceOf(ProtectedModeDisableSignalData::class, $disable);
        $this->mode->requestDisable($disable);
        $this->agent->onTick();

        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertSame(ProtectedModeRuntime::PHASE_INACTIVE, $row?->phase);

        $reply3 = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $reply3);
        $this->assertTrue($reply3->isOk());
        $this->assertSame('cli-disable', $reply3->correlationId);
        $this->assertSame(['phase' => 'inactive'], $reply3->payload);
    }

    public function testATestFreezeOutlivesARestartAndIsOpenedByItsInitiator(): void
    {
        // The scenario reproduced live on 07.10.2026 (HIL-1510): enter, the master dies, and the open
        // that follows was dropped as "no freeze is active here" - the file was the only way out.
        $this->agent->onSignalCommand(new CommandRequestDTO('cli-enter', CliCommands::PROTECTED_MODE_TEST_ENTER, [
            ProtectedModeCommandConstants::FIELD_OPERATION => 'acceptance_probe',
        ]), '', '');
        $enable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->mode->requestEnable($enable);
        // No worker server here to walk the roster, so its stop is reported the way it reports one.
        $this->mode->onRosterStopped();

        $entered = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $entered);
        $this->assertTrue($entered->isOk());
        $this->assertFileExists($this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME);

        $this->restartTheNode();
        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertSame(ProtectedModeRuntime::PHASE_ACTIVE, $row?->phase);
        $this->assertSame('acceptance_probe', $row?->operation);

        $this->agent->onSignalCommand(new CommandRequestDTO('cli-open', CliCommands::PROTECTED_MODE_TEST_OPEN, []), '', '');
        $disable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_DISABLE);
        $this->assertInstanceOf(ProtectedModeDisableSignalData::class, $disable);
        $this->mode->requestDisable($disable);
        $this->agent->onTick();

        $this->assertSame(ProtectedModeRuntime::PHASE_INACTIVE, $row?->phase);
        $opened = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $opened);
        $this->assertTrue($opened->isOk());
        $this->assertSame('cli-open', $opened->correlationId);
        $this->assertSame(['phase' => 'inactive'], $opened->payload);
        $this->assertFileDoesNotExist($this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME);
    }

    public function testAManualWindowOutlivesARestartAndIsDrivenFromTheConsole(): void
    {
        // The window comes back empty - the restart burned its passes and its circle - and the console
        // that opened it gets in again by a new code and closes it.
        $this->agent->onSignalCommand(new CommandRequestDTO('cli-enable', CliCommands::MAINTENANCE_ENABLE, []), '', '');
        $enable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $enable);
        $this->mode->requestEnable($enable);
        $this->assertFileExists($this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME);

        $this->restartTheNode();
        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertSame(ProtectedModeRuntime::PHASE_VERIFYING, $row?->phase);
        $this->assertSame(ProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, $row?->entryMode);

        $this->agent->onSignalCommand(new CommandRequestDTO('cli-pass', CliCommands::MAINTENANCE_PASS, []), '', '');
        $pass = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $pass);
        $this->mode->requestPass($pass);
        $this->agent->onTick();

        $this->assertSame([$pass->passHash], $row?->passHashes);
        $minted = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $minted);
        $this->assertTrue($minted->isOk());
        $this->assertSame('cli-pass', $minted->correlationId);

        $this->agent->onSignalCommand(new CommandRequestDTO('cli-disable', CliCommands::MAINTENANCE_DISABLE, []), '', '');
        $disable = $this->nextRequest(SignalTypeConstants::PROTECTED_MODE_DISABLE);
        $this->assertInstanceOf(ProtectedModeDisableSignalData::class, $disable);
        $this->mode->requestDisable($disable);
        $this->agent->onTick();

        $this->assertSame(ProtectedModeRuntime::PHASE_INACTIVE, $row?->phase);
        $closed = $this->nextRequest(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $closed);
        $this->assertTrue($closed->isOk());
        $this->assertSame(['phase' => 'inactive'], $closed->payload);
        $this->assertFileDoesNotExist($this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME);
    }

    /**
     * Restarts the node: the memory goes and the freeze file stays, as when a master dies under a freeze.
     *
     * Everything the process held is built anew - the runtime, the queue, the cluster context, the
     * index agent and the switch - and what comes back is what {@see DaemonManager} brings up over
     * it: the body of its restore at the end of boot, which mounts the freeze row, makes this
     * process its writer and puts the file back on it, and then the switch its run builds, which
     * adopts the row.
     *
     * @throws EnvException When the daemon log path the freeze is stored beside cannot be read
     * @throws ProtectedModeFreezeUnreadableException When the freeze left on disk cannot be read
     * @throws RtActionsCollectionNameNullException When the freeze row has no collection name to sync under
     * @throws RtTruthSourceWriteNotAllowedException When this process may not write the freeze row
     */
    private function restartTheNode(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        Hilos::$sr = new SignalRouter();
        Hilos::$cluster = new ClusterContext();
        Hilos::$rt = new IntegrationManualRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);

        $row = new ProtectedModeFreezeStore()->load();
        if ($row === null) {
            $this->fail('The node went down under no freeze: nothing was left on disk.');
        }
        Hilos::$rt->hilosProtectedModeRuntime?->actions->restoreFromDisk($row);

        $this->agent = new IntegrationManualIndexAgent();
        Hilos::$cluster->registerProtectedModeInitiatorRelay(new IntegrationManualRelay($this->agent));
        $this->mode = new StandaloneProtectedMode(new DaemonProtectedModeExecutor());
        $this->mode->adoptStandingFreeze();
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

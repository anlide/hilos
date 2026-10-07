<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\ManualMaintenanceOutcome;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The index agent keeps manual maintenance separate from the test and restore ladders.
 */
final class ManualMaintenanceIndexAgentTest extends TestCase
{
    /** @var ?EnvAccessor Previous environment accessor */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context */
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;

        parent::tearDown();
    }

    public function testEnableCarriesBrowserIdentityAndAnswersOnReadyOnly(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];

        $agent->enable('browser-key', 'session-hash', static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        });

        $request = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $request);
        $this->assertSame(StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $request->operation);
        $this->assertSame(StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW, $request->entryMode);
        $this->assertSame('browser-key', $request->initiatorAcceptKey);
        $this->assertSame('session-hash', $request->initiatorSessionTokenHash);
        $this->assertSame(HilosAgentType::HILOS_INDEX, $request->initiatorAgentType);
        $this->assertNull($request->initiatorAgentIndex);
        $this->assertSame([], $outcomes);

        $agent->onProtectedModeReady();
        $agent->onProtectedModeReady();

        $this->assertCount(1, $outcomes);
        $this->assertSame(ManualMaintenanceOutcome::SUCCEEDED, $outcomes[0]->status);
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $outcomes[0]->phase);
    }

    public function testCliEnableHasNoBrowserAndARefusalReleasesTheWaiter(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $agent->enable('', null, $reply);
        $request = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $request);
        $this->assertSame('', $request->initiatorAcceptKey);
        $this->assertNull($request->initiatorSessionTokenHash);

        $agent->onProtectedModeRefused('cluster refuses direct entry');
        $this->assertSame(ManualMaintenanceOutcome::REFUSED, $outcomes[0]->status);
        $this->assertSame('cluster refuses direct entry', $outcomes[0]->reason);

        $agent->enable('', null, $reply);
        $this->assertInstanceOf(ProtectedModeEnableSignalData::class, $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE));
    }

    public function testPreflightRefusesMissingAndOccupiedRows(): void
    {
        Hilos::$rt = null;
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $agent->enable('', null, $reply);
        $this->assertStringContainsString('not mounted', (string)$outcomes[0]->reason);

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        $agent->enable('', null, $reply);
        $this->assertStringContainsString('already active', (string)$outcomes[1]->reason);

        $this->row(StateProtectedModeRuntime::PHASE_ACTIVE, 'restore');
        $agent->enable('', null, $reply);
        $this->assertStringContainsString('restore', (string)$outcomes[2]->reason);
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE));
    }

    public function testEnableRequiresBothHalvesOfABrowserIdentity(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $agent->enable('browser-key', null, $reply);
        $agent->enable('', 'session-hash', $reply);
        $agent->enable('browser-key', '', $reply);

        $this->assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            $this->assertSame(ManualMaintenanceOutcome::REFUSED, $outcome->status);
        }
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE));
    }

    public function testDisableAndPassRequireTheExactManualWindowAndInitiator(): void
    {
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING, 'restore');
        $agent->disable($reply);
        $this->assertStringContainsString('not manual', (string)$outcomes[0]->reason);

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING, initiatorType: 'other-agent');
        $agent->mint($reply);
        $this->assertStringContainsString('another agent', (string)$outcomes[1]->reason);

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING, entryMode: StateProtectedModeRuntime::ENTRY_MODE_FREEZE);
        $agent->disable($reply);
        $this->assertStringContainsString('not manual', (string)$outcomes[2]->reason);

        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent->mint($reply);
        $this->assertStringContainsString('inactive', (string)$outcomes[3]->reason);
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS));
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_DISABLE));
    }

    public function testDisableWaitsForInactiveAndPassWaitsForItsOwnHash(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $agent->disable($reply);
        $this->assertNotNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_DISABLE));
        $agent->onTick();
        $this->assertSame([], $outcomes);

        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent->onTick();
        $this->assertSame(ManualMaintenanceOutcome::SUCCEEDED, $outcomes[0]->status);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $outcomes[0]->phase);

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        $agent->mint($reply);
        $request = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $request);
        $agent->onTick();
        $this->assertCount(1, $outcomes);

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING, passHashes: [$request->passHash]);
        $agent->onTick();
        $agent->onTick();
        $this->assertCount(2, $outcomes);
        $this->assertSame(ManualMaintenanceOutcome::SUCCEEDED, $outcomes[1]->status);
        $this->assertSame(hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, (string)$outcomes[1]->pass), $request->passHash);
    }

    public function testSingleFlightCoversManualAndTestCommandsAndTimeoutIsUnconfirmed(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };

        $agent->enable('', null, $reply);
        $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE);
        $agent->enable('', null, $reply);
        $this->assertStringContainsString('still in flight', (string)$outcomes[0]->reason);

        $agent->onSignalCommand(
            new CommandRequestDTO('test-busy', CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']),
            '',
            '',
        );
        $busyReply = $this->nextSignal(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $busyReply);
        $this->assertSame(CommandConstants::STATUS_ERROR, $busyReply->status);
        $this->assertStringContainsString('still in flight', (string)$busyReply->payload[CommandConstants::FIELD_MESSAGE]);

        $agent->ageManualWait();
        $agent->onTick();
        $agent->onProtectedModeReady();
        $this->assertCount(2, $outcomes);
        $this->assertSame(ManualMaintenanceOutcome::UNCONFIRMED, $outcomes[1]->status);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $outcomes[1]->phase);
    }

    public function testAnExistingTestOrOperatorWaitRefusesAManualRequest(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent = new ManualMaintenanceTestAgent();
        $agent->onSignalCommand(new CommandRequestDTO('test-enter', CliCommands::PROTECTED_MODE_TEST_ENTER, [
            'operation' => 'restore',
        ]), '', '');
        $this->assertNotNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE));

        $outcomes = [];
        $reply = static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        };
        $agent->enable('', null, $reply);
        $this->assertStringContainsString('still in flight', (string)$outcomes[0]->reason);
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE));

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING, 'restore');
        $otherAgent = new ManualMaintenanceTestAgent();
        $otherAgent->onSignalCommand(new CommandRequestDTO('test-pass', CliCommands::PROTECTED_MODE_TEST_PASS, []), '', '');
        $this->assertNotNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS));
        $otherAgent->disable($reply);
        $this->assertStringContainsString('still in flight', (string)$outcomes[1]->reason);
    }

    public function testPassTimeoutDropsTheClearValueAndCallsBackOnlyOnce(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $agent->mint(static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        });
        $request = $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS);
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $request);

        $agent->ageManualWait();
        $agent->onTick();
        $this->assertSame(ManualMaintenanceOutcome::UNCONFIRMED, $outcomes[0]->status);
        $this->assertNull($outcomes[0]->pass);
        $this->assertStringContainsString('close and reopen', (string)$outcomes[0]->reason);

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING, passHashes: [$request->passHash]);
        $agent->onTick();
        $this->assertCount(1, $outcomes);
    }

    public function testMissingRuntimeRowCannotConfirmDisable(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        $agent = new ManualMaintenanceTestAgent();
        $outcomes = [];
        $agent->disable(static function (ManualMaintenanceOutcome $outcome) use (&$outcomes): void {
            $outcomes[] = $outcome;
        });
        $this->nextSignal(SignalTypeConstants::PROTECTED_MODE_DISABLE);

        Hilos::$rt = null;
        $agent->onTick();
        $this->assertSame([], $outcomes);

        $agent->ageManualWait();
        $agent->onTick();
        $this->assertSame(ManualMaintenanceOutcome::UNCONFIRMED, $outcomes[0]->status);
        $this->assertNull($outcomes[0]->phase);
    }

    public function testTestCommandsCannotDriveManualMaintenance(): void
    {
        $this->row(StateProtectedModeRuntime::PHASE_INACTIVE);
        $agent = new ManualMaintenanceTestAgent();
        $agent->onSignalCommand(new CommandRequestDTO('enter', CliCommands::PROTECTED_MODE_TEST_ENTER, [
            'operation' => StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
        ]), '', '');
        $this->assertRefusedCommand('manual maintenance');
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_ENABLE));

        $this->row(StateProtectedModeRuntime::PHASE_VERIFYING);
        foreach ([CliCommands::PROTECTED_MODE_TEST_OPEN, CliCommands::PROTECTED_MODE_TEST_PASS, CliCommands::PROTECTED_MODE_TEST_CLOSE] as $command) {
            $agent->onSignalCommand(new CommandRequestDTO($command, $command, []), '', '');
            $this->assertRefusedCommand($command === CliCommands::PROTECTED_MODE_TEST_CLOSE ? 'direct verification' : 'manual maintenance');
        }
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_DISABLE));
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_PASS));
        $this->assertNull($this->nextSignal(SignalTypeConstants::PROTECTED_MODE_REFREEZE));
    }

    /**
     * @param string $phase Runtime phase
     * @param ?string $operation Operation on an occupied row
     * @param ?string $initiatorType Recorded initiator type
     * @param ?string $entryMode How the row was entered
     * @param list<string> $passHashes Pass hashes on the row
     */
    private function row(
        string $phase,
        ?string $operation = StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
        ?string $initiatorType = HilosAgentType::HILOS_INDEX,
        ?string $entryMode = StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
        array $passHashes = [],
    ): void {
        Hilos::$rt = new ManualMaintenanceTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::entryMode => $phase === StateProtectedModeRuntime::PHASE_INACTIVE ? null : $entryMode,
            StateProtectedModeRuntime::operation => $phase === StateProtectedModeRuntime::PHASE_INACTIVE ? null : $operation,
            StateProtectedModeRuntime::initiatorAgentType => $phase === StateProtectedModeRuntime::PHASE_INACTIVE ? null : $initiatorType,
            StateProtectedModeRuntime::initiatorAgentIndex => null,
            StateProtectedModeRuntime::passHashes => $passHashes,
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
        ]));
    }

    /**
     * @param string $type Signal type sought in the queue
     * @return mixed Payload of the first matching queued signal, or null
     */
    private function nextSignal(string $type): mixed
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === $type) {
                return $signal->data;
            }
        }

        return null;
    }

    /**
     * @param string $reasonFragment Reason the refused command must contain
     */
    private function assertRefusedCommand(string $reasonFragment): void
    {
        $reply = $this->nextSignal(SignalTypeConstants::COMMAND_REPLY);
        $this->assertInstanceOf(CommandReplyDTO::class, $reply);
        $this->assertSame(CommandConstants::STATUS_ERROR, $reply->status);
        $this->assertStringContainsString($reasonFragment, (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }
}

/** Test carrier that exposes the three transport-neutral protected operations. */
final class ManualMaintenanceTestAgent extends AbstractHilosIndexAgent
{
    /**
     * @param ?string $agentIndex Agent index, or null for the singleton index agent
     */
    public function __construct(?string $agentIndex = null)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param string $acceptKey Browser accept key, or empty for CLI
     * @param ?string $sessionHash Browser session hash, or null for CLI
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

    /** Backdates the wait to exercise its expiry in one tick. */
    public function ageManualWait(): void
    {
        $since = new ReflectionProperty(AbstractHilosIndexAgent::class, 'manualMaintenanceSince');
        $since->setValue($this, microtime(true) - CommandChannelWindows::AGENT_WAIT_SECONDS - 1.0);
    }
}

/** Runtime context whose only item is the framework protected-mode row. */
final class ManualMaintenanceTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

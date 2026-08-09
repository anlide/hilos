<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\ProtectedModeTestDriverTrait;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the protected-mode test driver on an agent (HIL-344).
 *
 * The driver's whole reason to exist is that the core answers nobody when it drops a request:
 * a repeat enable, a disable with no freeze and a disable from the wrong agent are all
 * log-and-return paths. So these cases are about the two things a caller must always get - a
 * reason instead of a timeout, and a reply that means the mode has really moved rather than
 * that the request was accepted.
 */
final class ProtectedModeTestDriverTest extends TestCase
{
    private const string INITIATOR_TYPE = 'driver-test-initiator';

    /** @var ?EnvAccessor Env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$sr = new SignalRouter();
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;

        parent::tearDown();
    }

    public function testEnterOnAnAlreadyActiveModeIsRefusedAndAsksForNothing(): void
    {
        // The core drops a repeat enable with a warning and replies to nobody, so without this
        // pre-check the caller would read a mute timeout instead of the reason.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);
        $agent = new DriverTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']));

        $this->assertRefused($this->singleReply(), 'already active');
        $this->assertNull($this->nextEnableOrDisable(), 'A refused enter must queue no enable at all.');
    }

    public function testEnterAnswersOnlyOnceTheFreezeIsReady(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $agent = new DriverTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']));

        $this->assertSame(
            SignalTypeConstants::PROTECTED_MODE_ENABLE,
            $this->nextEnableOrDisable(),
            'Accepting the command asks the daemon for the freeze.',
        );
        $this->assertSame([], $this->replies(), 'Nothing is answered while the freeze is still taking hold.');

        // The row is left INACTIVE on purpose: the relay reaches the agent by its own path, so
        // this worker's copy can still be stale when it arrives - it read `inactive` in a live
        // run, and an initiator on a cluster follower stays at `activating` for the whole
        // freeze by design. Ready means every node quiesced, and that is what must be reported.
        $agent->onProtectedModeReady();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_ACTIVE,
            $reply->payload[ProtectedModeCommandConstants::FIELD_PHASE],
            'The reply is a verdict: the ready relay means the freeze took hold everywhere.',
        );
    }

    public function testAnEnterThatNeverBecomesReadyExpiresWithItsReason(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $agent = new DriverTestAgent(null);
        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']));
        $this->nextEnableOrDisable();

        $agent->onTick();
        $this->assertSame([], $this->replies(), 'The window has not run out yet.');

        $agent->ageProtectedModeWait();
        $agent->onTick();

        // The enable can be dropped without a word to this agent, so nothing else would ever
        // end the wait - the caller would sit until the transport gave up mutely.
        $this->assertRefused($this->singleReply(), 'become ready');
    }

    public function testLeaveFromAnAgentThatIsNotTheInitiatorIsRefused(): void
    {
        // Authorization is by initiator identity exactly as in production; there is no forced
        // lift, and the core would drop this disable silently.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, 'somebody-else', null);
        $agent = new DriverTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_LEAVE));

        $this->assertRefused($this->singleReply(), 'somebody-else');
        $this->assertNull($this->nextEnableOrDisable(), 'A refused leave must queue no disable.');
    }

    public function testLeaveFromTheInitiatorWithAMatchingIndexIsAccepted(): void
    {
        // The row carries the index as an int and the agent as a string; a comparison that got
        // this wrong would refuse the one agent actually entitled to lift.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, 7);
        $agent = new DriverTestAgent('7');

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_LEAVE));

        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_DISABLE, $this->nextEnableOrDisable());
        $this->assertSame([], $this->replies(), 'Leave answers on the lift, not on acceptance.');
    }

    public function testLeaveAnswersWhenTheRowIsBackToInactive(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null);
        $agent = new DriverTestAgent(null);
        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_LEAVE));
        $this->nextEnableOrDisable();

        $agent->onTick();
        $this->assertSame([], $this->replies(), 'Still frozen, so there is nothing to report yet.');

        // Answering here is what lets a caller load a page on the next line without racing the
        // agents coming back up.
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $agent->onTick();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_INACTIVE,
            $reply->payload[ProtectedModeCommandConstants::FIELD_PHASE],
        );
    }

    public function testLeaveWithNoFreezeInFlightIsRefused(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $agent = new DriverTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_LEAVE));

        $this->assertRefused($this->singleReply(), 'no freeze is active');
    }

    public function testEnterWithoutAnOperationNameIsRefused(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $agent = new DriverTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => '']));

        $this->assertRefused($this->singleReply(), 'operation');
        $this->assertNull($this->nextEnableOrDisable());
    }

    public function testASecondDriveWhileOneIsInFlightIsRefused(): void
    {
        // Accepting it would overwrite the correlation id and strand the first caller on the
        // channel until its own timeout.
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null);
        $agent = new DriverTestAgent(null);
        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']));
        $this->nextEnableOrDisable();

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']));

        $this->assertRefused($this->singleReply(), 'still in flight');
    }

    public function testDrivingAProjectWithoutTheModeMountedIsRefused(): void
    {
        Hilos::$rt = null;
        $agent = new DriverTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_ENTER, ['operation' => 'restore']));

        $this->assertRefused($this->singleReply(), 'not mounted');
    }

    /**
     * Mounts the freeze row in the phase and initiator identity the case needs.
     *
     * @param string $phase Freeze phase to mount
     * @param ?string $initiatorType Initiator agent type recorded on the row
     * @param ?int $initiatorIndex Initiator agent index recorded on the row
     */
    private function freeze(string $phase, ?string $initiatorType, ?int $initiatorIndex): void
    {
        Hilos::$rt = new DriverTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAgentType => $initiatorType,
            StateProtectedModeRuntime::initiatorAgentIndex => $initiatorIndex,
        ]));
    }

    /**
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @return CommandRequestDTO Request addressed to the agent under test
     */
    private function request(string $command, array $payload = []): CommandRequestDTO
    {
        return new CommandRequestDTO(correlationId: 'corr-1', command: $command, payload: $payload);
    }

    /**
     * Drains the queue and returns the command replies the agent produced.
     *
     * @return list<CommandReplyDTO> Replies queued since the last drain
     */
    private function replies(): array
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }

        return $replies;
    }

    /**
     * @return CommandReplyDTO The one reply the agent produced
     */
    private function singleReply(): CommandReplyDTO
    {
        $replies = $this->replies();
        $this->assertCount(1, $replies, 'Every path answers exactly once.');

        return $replies[0];
    }

    /**
     * Consumes queued signals until a protected-mode request appears, and names its type.
     *
     * @return ?string Signal type of the enable/disable request, or null when none was queued
     */
    private function nextEnableOrDisable(): ?string
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $type = $signal->signalType->getType();
            if ($type === SignalTypeConstants::PROTECTED_MODE_ENABLE
                || $type === SignalTypeConstants::PROTECTED_MODE_DISABLE) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param CommandReplyDTO $reply Reply under assertion
     * @param string $reasonFragment Text the refusal must name, so a caller is told why
     */
    private function assertRefused(CommandReplyDTO $reply, string $reasonFragment): void
    {
        $this->assertSame(CommandConstants::STATUS_ERROR, $reply->status);
        $this->assertStringContainsString(
            $reasonFragment,
            (string)$reply->payload[CommandConstants::FIELD_MESSAGE],
        );
    }
}

/**
 * A minimal initiator agent carrying the driver, with the wait window reachable from a test.
 */
final class DriverTestAgent extends AbstractAgent
{
    use ProtectedModeTestDriverTrait;

    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'driver-test-initiator';

    /**
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function __construct(?string $agentIndex = null)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param CommandRequestDTO $data Command request routed to this agent
     */
    public function handle(CommandRequestDTO $data): void
    {
        $this->handleProtectedModeTestCommand($data);
    }

    public function onTick(): void
    {
        $this->tickProtectedModeTestDriver();
    }

    /**
     * Backdates the wait so the next tick sees the window as expired.
     *
     * Reaches the trait's own property directly - a trait's private members are flattened into
     * the using class - which is why this needs no reflection.
     */
    public function ageProtectedModeWait(): void
    {
        $this->protectedModeTestSince -= self::PROTECTED_MODE_TEST_WAIT_SECONDS + 1.0;
    }

    public function onStop(): void
    {
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class DriverTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the operator half of the verification window (HIL-481).
 *
 * These three commands are the only way back out of a freeze now that a finished restore stops
 * lifting one by itself, so what is checked here is what an operator at a terminal must be able
 * to rely on: that a refusal states its reason instead of timing out, that success means the row
 * really moved, and above all that the minted pass reaches the terminal and nothing else - the
 * signal carries its hash, and the agent hands the clear value back only once the row confirms.
 *
 * The mint answers to a second name as well (HIL-616), and so does the close (HIL-704): the test
 * path's, whose carrier is a different initiator. It is the same handler behind both, which is
 * what these cases pin - a separate name must not become a separate set of rules.
 */
final class ProtectedModeOperatorTest extends TestCase
{
    private const string INITIATOR_TYPE = 'operator-test-initiator';

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
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;

        parent::tearDown();
    }

    public function testAPassTravelsAsAHashAndComesBackClearOnlyOnceTheRowHoldsIt(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_PASS));

        $sent = $this->nextPassRequest();
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $sent);
        $this->assertSame([], $this->replies(), 'The pass is not handed over before the row holds it.');

        $agent->onTick();
        $this->assertSame([], $this->replies(), 'A pass that has not landed yet is not an answer.');

        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, [$sent->passHash]);
        $agent->onTick();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);

        $pass = (string)$reply->payload[ProtectedModeCommandConstants::FIELD_PASS];
        $this->assertNotSame('', $pass, 'The operator terminal is the only place the key exists.');
        $this->assertSame(
            hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, $pass),
            $sent->passHash,
            'What travelled to the daemon must be the hash of exactly the key handed back.',
        );
        $this->assertNotSame($pass, $sent->passHash, 'The clear key must never ride the signal.');
    }

    public function testTwoPassesAreDifferentKeys(): void
    {
        // The circle size is the operator's business: calling it again mints another, and two
        // verifiers sharing one key would make the count of outstanding passes a lie.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_PASS));
        $first = $this->nextPassRequest()?->passHash;
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, [(string)$first]);
        $agent->onTick();
        $this->replies();

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_PASS));
        $second = $this->nextPassRequest()?->passHash;

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    public function testAPassThatNeverLandsExpiresWithItsReason(): void
    {
        // The core drops a pass against the wrong phase by logging a warning and replying to
        // nobody, so without this window the operator would read a mute timeout.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);
        $agent->handle($this->request(CliCommands::PROTECTED_MODE_PASS));
        $this->nextPassRequest();

        $agent->onTick();
        $this->assertSame([], $this->replies(), 'The window has not run out yet.');

        $agent->ageProtectedModeOperatorWait();
        $agent->onTick();

        $this->assertRefused($this->singleReply(), 'record the pass');
    }

    public function testTheTestNameMintsThroughTheVerySamePath(): void
    {
        // The whole reason a second name exists: a driven freeze is initiated by an agent the
        // operator's command does not belong to, so the test path needs its own name - and must
        // not get its own mint. Same request, same hash, same wait for the row.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_PASS));

        $sent = $this->nextPassRequest();
        $this->assertInstanceOf(ProtectedModePassSignalData::class, $sent);
        $this->assertSame([], $this->replies(), 'The pass is not handed over before the row holds it.');

        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, [$sent->passHash]);
        $agent->onTick();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $pass = (string)$reply->payload[ProtectedModeCommandConstants::FIELD_PASS];
        $this->assertSame(hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, $pass), $sent->passHash);
    }

    public function testTheTestNameIsRefusedOutsideTheWindowLikeTheOperatorName(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_PASS));

        $this->assertRefused($this->singleReply(), StateProtectedModeRuntime::PHASE_VERIFYING);
        $this->assertNull($this->nextPassRequest(), 'A refused pass must mint nothing at all.');
    }

    public function testTheTestNameIsRefusedFromAnAgentThatDidNotStartTheFreeze(): void
    {
        // Inheriting the identity check is the point of sharing the handler: a test name must
        // not become a way around the one rule the freeze has.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, 'somebody-else', null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_PASS));

        $this->assertRefused($this->singleReply(), 'somebody-else');
        $this->assertNull($this->nextPassRequest(), 'A refused pass must mint nothing at all.');
    }

    public function testAPassIsRefusedOutsideTheVerificationWindow(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_PASS));

        $this->assertRefused($this->singleReply(), StateProtectedModeRuntime::PHASE_VERIFYING);
        $this->assertNull($this->nextPassRequest(), 'A refused pass must mint nothing at all.');
    }

    public function testACommandFromAnAgentThatIsNotTheInitiatorIsRefused(): void
    {
        // Authorization is by initiator identity, exactly as production authorizes the release:
        // a stray agent must not open a system mid-operation.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, 'somebody-else', null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_OPEN));

        $this->assertRefused($this->singleReply(), 'somebody-else');
        $this->assertNull($this->nextExitRequest(), 'A refused open must queue no disable.');
    }

    public function testOpenLiftsFromAnyFrozenPhaseAndAnswersOnInactive(): void
    {
        // Deliberately the one command not gated on the window: it is the lever that gets a node
        // out of a freeze at all, and an operator holding a system stuck mid-restore must not be
        // told to reach some other phase first.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_OPEN));

        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_DISABLE, $this->nextExitRequest());
        $agent->onTick();
        $this->assertSame([], $this->replies(), 'Still frozen, so there is nothing to report yet.');

        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null, []);
        $agent->onTick();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_INACTIVE,
            $reply->payload[ProtectedModeCommandConstants::FIELD_PHASE],
        );
    }

    public function testCloseReturnsToTheFullFreezeAndAnswersOnActive(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, ['a-hash']);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_CLOSE));

        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_REFREEZE, $this->nextExitRequest());

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent->onTick();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_ACTIVE,
            $reply->payload[ProtectedModeCommandConstants::FIELD_PHASE],
        );
    }

    public function testCloseIsRefusedOutsideTheVerificationWindow(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_CLOSE));

        $this->assertRefused($this->singleReply(), StateProtectedModeRuntime::PHASE_VERIFYING);
        $this->assertNull($this->nextExitRequest(), 'A refused close must queue no refreeze.');
    }

    public function testTheTestNameClosesThroughTheVerySamePath(): void
    {
        // The same bargain the test mint struck: a driven freeze is initiated by an agent the
        // operator's close does not belong to, so the test path needs its own name - and must
        // not get its own refreeze. Same request, same wait for the row to read active.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, ['a-hash']);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_CLOSE));

        $this->assertSame(SignalTypeConstants::PROTECTED_MODE_REFREEZE, $this->nextExitRequest());
        $agent->onTick();
        $this->assertSame([], $this->replies(), 'Still in the window, so there is nothing to report yet.');

        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent->onTick();

        $reply = $this->singleReply();
        $this->assertSame(CommandConstants::STATUS_OK, $reply->status);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_ACTIVE,
            $reply->payload[ProtectedModeCommandConstants::FIELD_PHASE],
        );
    }

    public function testTheTestCloseIsRefusedOutsideTheWindowLikeTheOperatorName(): void
    {
        // The gate is what keeps the close out of teardown: a suite wanting its stand back has
        // to take the open, which is the exit that lifts from any phase.
        $this->freeze(StateProtectedModeRuntime::PHASE_ACTIVE, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_CLOSE));

        $this->assertRefused($this->singleReply(), StateProtectedModeRuntime::PHASE_VERIFYING);
        $this->assertNull($this->nextExitRequest(), 'A refused close must queue no refreeze.');
    }

    public function testTheTestCloseIsRefusedFromAnAgentThatDidNotStartTheFreeze(): void
    {
        // Inheriting the identity check is the point of sharing the handler: a test name must
        // not become a way around the one rule the freeze has.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, 'somebody-else', null, ['a-hash']);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_TEST_CLOSE));

        $this->assertRefused($this->singleReply(), 'somebody-else');
        $this->assertNull($this->nextExitRequest(), 'A refused close must queue no refreeze.');
    }

    /**
     * The ladder on a freeze nobody in this process transitioned into (HIL-699).
     *
     * Every case above stands on a row a phase change wrote. After a restart there was no phase
     * change: the row was read off disk by the master before any worker existed, and the agent
     * that meets it was started under a freeze already in force. That is the state the ticket
     * reported as "Refused: no freeze is active here", so the ladder is driven here off
     * {@see ProtectedModeRuntimeActions::restoreFromDisk()} and off nothing else.
     *
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     * @throws RtActionsCollectionNameNullException When the mounted item has no collection name
     * @throws RtTruthSourceWriteNotAllowedException When this process is not the row's writer
     */
    public function testTheOperatorLadderAnswersOnAFreezeThatCameBackFromDisk(): void
    {
        $this->freezeRestoredFromDisk();
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_OPEN));

        $this->assertSame(
            SignalTypeConstants::PROTECTED_MODE_DISABLE,
            $this->nextExitRequest(),
            'A node that came up frozen must be openable by the ladder, not answered "no freeze"',
        );
        $agent->onTick();
        $this->assertSame([], $this->replies(), 'Still frozen, so there is nothing to report yet.');
    }

    public function testACommandWithNoFreezeIsRefused(): void
    {
        $this->freeze(StateProtectedModeRuntime::PHASE_INACTIVE, null, null, []);
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_OPEN));

        $this->assertRefused($this->singleReply(), 'no freeze is active');
    }

    public function testACommandOnANodeWithoutTheModeMountedIsRefused(): void
    {
        Hilos::$rt = null;
        $agent = new OperatorTestAgent(null);

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_OPEN));

        $this->assertRefused($this->singleReply(), 'not mounted');
    }

    public function testASecondCommandWhileOneIsInFlightIsRefused(): void
    {
        // Accepting it would overwrite the correlation id and strand the first operator on the
        // channel until their own timeout.
        $this->freeze(StateProtectedModeRuntime::PHASE_VERIFYING, self::INITIATOR_TYPE, null, []);
        $agent = new OperatorTestAgent(null);
        $agent->handle($this->request(CliCommands::PROTECTED_MODE_PASS));
        $this->nextPassRequest();

        $agent->handle($this->request(CliCommands::PROTECTED_MODE_CLOSE));

        $this->assertRefused($this->singleReply(), 'still in flight');
    }

    /**
     * Mounts the freeze row in the phase, initiator identity and pass list the case needs.
     *
     * @param string $phase Freeze phase to mount
     * @param ?string $initiatorType Initiator agent type recorded on the row
     * @param ?int $initiatorIndex Initiator agent index recorded on the row
     * @param list<string> $passHashes Pass hashes the window is holding
     */
    private function freeze(string $phase, ?string $initiatorType, ?int $initiatorIndex, array $passHashes): void
    {
        Hilos::$rt = new OperatorTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAgentType => $initiatorType,
            StateProtectedModeRuntime::initiatorAgentIndex => $initiatorIndex,
            StateProtectedModeRuntime::passHashes => $passHashes,
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]));
    }

    /**
     * Mounts the freeze the way a restarted node comes back under it, and nothing else.
     *
     * The row is put in place by the restore rather than by a transition, and this process is
     * made its writer for the same reason the master is: the restore is a write, and a write
     * asks who owns the row.
     *
     * @throws InvalidFormatException When the fixture row is not one the state can be built from
     * @throws RtActionsCollectionNameNullException When the mounted item has no collection name
     * @throws RtTruthSourceWriteNotAllowedException When this process is not the row's writer
     */
    private function freezeRestoredFromDisk(): void
    {
        Hilos::$rt = new OperatorTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);

        Hilos::$rt->hilosProtectedModeRuntime?->actions->restoreFromDisk(StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::operation => 'restore',
            StateProtectedModeRuntime::initiatorAcceptKey => 'accept-7',
            StateProtectedModeRuntime::initiatorSessionTokenHash => 'session-hash-of-the-operator',
            StateProtectedModeRuntime::initiatorAgentType => self::INITIATOR_TYPE,
            StateProtectedModeRuntime::passHashes => ['hash-of-a-pass'],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]));
    }

    /**
     * @param string $command Command-channel wire name
     * @return CommandRequestDTO Request addressed to the agent under test
     */
    private function request(string $command): CommandRequestDTO
    {
        return new CommandRequestDTO(correlationId: 'corr-1', command: $command, payload: []);
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
     * Consumes queued signals until a minted pass appears, and hands back its payload.
     *
     * @return ?ProtectedModePassSignalData Pass request the agent queued, or null when it queued none
     */
    private function nextPassRequest(): ?ProtectedModePassSignalData
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof ProtectedModePassSignalData) {
                return $signal->data;
            }
        }

        return null;
    }

    /**
     * Consumes queued signals until an exit from the freeze appears, and names its type.
     *
     * @return ?string Signal type of the disable/refreeze request, or null when none was queued
     */
    private function nextExitRequest(): ?string
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $type = $signal->signalType->getType();
            if ($type === SignalTypeConstants::PROTECTED_MODE_DISABLE
                || $type === SignalTypeConstants::PROTECTED_MODE_REFREEZE) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param CommandReplyDTO $reply Reply under assertion
     * @param string $reasonFragment Text the refusal must name, so an operator is told why
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
 * A minimal initiator agent carrying the operator commands, with the wait window reachable
 * from a test.
 */
final class OperatorTestAgent extends AbstractAgent
{
    use ProtectedModeOperatorTrait;

    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'operator-test-initiator';

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
        $this->handleProtectedModeOperatorCommand($data);
    }

    public function onTick(): void
    {
        $this->tickProtectedModeOperator();
    }

    /**
     * Backdates the wait so the next tick sees the window as expired.
     *
     * Reaches the trait's own property directly - a trait's private members are flattened into
     * the using class - which is why this needs no reflection.
     */
    public function ageProtectedModeOperatorWait(): void
    {
        $this->protectedModeOperatorSince -= self::PROTECTED_MODE_OPERATOR_WAIT_SECONDS + 1.0;
    }

    public function onStop(): void
    {
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class OperatorTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

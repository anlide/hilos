<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Throwable;

/**
 * Drives protected mode from a test, through the one entry the mode actually has.
 *
 * An agent that uses this answers `test:protected-mode:enter`, `test:protected-mode:leave` and
 * `test:protected-mode:open` by calling {@see AbstractAgent::requestProtectedModeEnable()} /
 * {@see AbstractAgent::requestProtectedModeVerify()} /
 * {@see AbstractAgent::requestProtectedModeDisable()} - the same requests a restore makes. No
 * state is forced and no second entry is opened, which is the point: the initiator identity
 * those requests record is what authorizes the later release and what the agent-start gate
 * lets through, so a synthetic entry would exercise a path production does not have.
 *
 * **The drive is three steps because production is three steps.** Leave means "the driven
 * operation is over" and lands in the verification window, exactly where a finished restore now
 * lands; open is the separate, explicit lift. Nothing opens a system by finishing its own operation, on either path. The
 * open is a `test:` command of its own rather than the operator's
 * ({@see ProtectedModeOperatorTrait}) because a command routes to exactly one agent type per
 * project and that one belongs to the agent running real operations - while a freeze may only
 * be driven by the agent the row records as its initiator.
 *
 * A trait rather than a base class because the two carriers share no ancestor but
 * {@see AbstractAgent}, and putting the commands there would hand a test-drive of the freeze
 * to every agent of every project - the same reasoning that made
 * {@see CommandChannelClientTrait} a trait.
 *
 * **The reply is a verdict, not an acknowledgement.** Every command answers once the mode has
 * really moved: enter from {@see onProtectedModeReady()}, leave when this node's row reads
 * verifying, open when it is back to inactive. That is what lets a test act on the next line
 * instead of polling.
 *
 * **Why the pre-checks exist at all.** The core drops a repeat enable, an unauthorized disable
 * and a disable with no freeze by logging a warning and replying to nobody. Without checking
 * the row first, those cases would reach the caller as a mute timeout instead of a reason, and
 * the timeout is the least informative thing a test can be told.
 *
 * **There is deliberately no production-environment refusal here.** The CLI half of this pair
 * refuses on a production-like env ({@see TestOnlyCommand}), but the command socket
 * authenticates nobody and e2e reaches it directly over TCP - Playwright has no PHP to run the
 * CLI with. That ungated socket path is an existing property of the command channel, shared
 * with `setAdmin` and `connection:test:drop`, and it is recorded here so the next reader does
 * not take its absence for an oversight and "fix" the e2e out of existence.
 */
trait ProtectedModeTestDriverTrait
{
    /**
     * @var float Seconds this agent waits for the mode to move before answering a refusal
     *
     * Deliberately the innermost of three nested windows: the CLI gives up after
     * {@see CommandChannelClientTrait}'s budget and the command channel releases a held request
     * after {@see CommandClient}'s, both longer than this. So the caller gets this agent's
     * informative refusal rather than either mute timeout.
     */
    private const float PROTECTED_MODE_TEST_WAIT_SECONDS = 3.0;

    /** @var ?string Correlation id of the drive command being awaited, or null when idle */
    private ?string $protectedModeTestCorrelationId = null;

    /** @var float Microtime the awaited command was accepted at */
    private float $protectedModeTestSince = 0.0;

    /** @var string Phase whose arrival answers the awaited command; empty while an enter awaits its ready relay */
    private string $protectedModeTestAwaitedPhase = '';

    /**
     * Whether this command is one of the three this trait drives.
     *
     * @param string $command Command-channel wire name
     * @return bool True when {@see handleProtectedModeTestCommand()} owns it
     */
    protected function isProtectedModeTestCommand(string $command): bool
    {
        return $command === CliCommands::PROTECTED_MODE_TEST_ENTER
            || $command === CliCommands::PROTECTED_MODE_TEST_LEAVE
            || $command === CliCommands::PROTECTED_MODE_TEST_OPEN;
    }

    /**
     * Accepts one drive command, refusing outright anything the core would drop silently.
     *
     * @param CommandRequestDTO $data Command request routed to this agent
     */
    protected function handleProtectedModeTestCommand(CommandRequestDTO $data): void
    {
        if ($this->protectedModeTestCorrelationId !== null) {
            // One drive at a time: a second would overwrite the correlation id and strand the
            // first caller on the channel until its own timeout.
            $this->refuseProtectedModeTest($data->correlationId, 'another protected-mode command is still in flight');

            return;
        }

        $freeze = $this->protectedModeTestRow();
        if ($freeze === null) {
            $this->refuseProtectedModeTest(
                $data->correlationId,
                'protected mode is not mounted on this node, so it cannot be driven here',
            );

            return;
        }

        if ($data->command === CliCommands::PROTECTED_MODE_TEST_ENTER) {
            $this->enterProtectedModeForTest($data, $freeze);

            return;
        }

        if ($data->command === CliCommands::PROTECTED_MODE_TEST_LEAVE) {
            $this->leaveProtectedModeForTest($data, $freeze);

            return;
        }

        $this->openProtectedModeForTest($data, $freeze);
    }

    /**
     * Answers a pending enter once the freeze has actually taken hold.
     *
     * A ready with nothing pending is ignored: this node can be relayed a ready for a freeze
     * some other caller drove.
     *
     * Reports the relay's own meaning rather than this worker's copy of the row, and that is
     * not a shortcut - reading the row here answers wrongly twice over. The relay reaches this
     * agent by its own path, so the phase written on the master has not necessarily synced
     * into this worker yet (it read `inactive` in a live run); and an initiator sitting on a
     * cluster follower stays at `activating` for the whole freeze by design, because `active`
     * is the leader-local marker that every node has quiesced. Ready means every node has
     * quiesced, on every topology, which is exactly what the caller asked.
     */
    public function onProtectedModeReady(): void
    {
        if ($this->protectedModeTestCorrelationId === null || $this->protectedModeTestAwaitedPhase !== '') {
            return;
        }

        $this->answerProtectedModeTest(StateProtectedModeRuntime::PHASE_ACTIVE);
    }

    /**
     * Finishes a pending leave or open, and expires any command whose window ran out.
     *
     * The carrier calls this from its own onTick. Neither of those two has a ready relay to
     * answer from - each is observed as this node's row reaching the phase it asked for, which
     * for the open is also exactly the moment a caller may load a page without racing the agents
     * coming back up.
     */
    protected function tickProtectedModeTestDriver(): void
    {
        if ($this->protectedModeTestCorrelationId === null) {
            return;
        }

        $phase = $this->protectedModeTestRow()?->phase ?? StateProtectedModeRuntime::PHASE_INACTIVE;
        if ($this->protectedModeTestAwaitedPhase !== '' && $phase === $this->protectedModeTestAwaitedPhase) {
            $this->answerProtectedModeTest($phase);

            return;
        }

        if ((microtime(true) - $this->protectedModeTestSince) < self::PROTECTED_MODE_TEST_WAIT_SECONDS) {
            return;
        }

        $waited = self::PROTECTED_MODE_TEST_WAIT_SECONDS;
        $target = $this->protectedModeTestAwaitedPhase === ''
            ? 'become ready'
            : "reach '{$this->protectedModeTestAwaitedPhase}'";
        $this->refuseProtectedModeTest(
            $this->protectedModeTestCorrelationId,
            "protected mode did not {$target} within {$waited}s (phase: {$phase})",
        );
        $this->clearProtectedModeTest();
    }

    /**
     * Asks for the freeze, unless this node's row says the core would drop the request.
     *
     * @param CommandRequestDTO $data Enter request carrying the operation name and accept key
     * @param ProtectedModeRuntime $freeze This node's freeze row
     */
    private function enterProtectedModeForTest(CommandRequestDTO $data, ProtectedModeRuntime $freeze): void
    {
        if ($freeze->phase !== StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->refuseProtectedModeTest(
                $data->correlationId,
                "protected mode is already {$freeze->phase} for '{$freeze->operation}'",
            );

            return;
        }

        $operation = $data->payload[ProtectedModeCommandConstants::FIELD_OPERATION] ?? null;
        if (!is_string($operation) || $operation === '') {
            $this->refuseProtectedModeTest($data->correlationId, 'enter needs a non-empty operation name');

            return;
        }

        $acceptKey = $data->payload[CommandConstants::FIELD_ACCEPT_KEY] ?? null;

        $this->armProtectedModeTest($data->correlationId, '');

        try {
            // external-boundary: no accept key is a CLI initiator, the identity BackupAgent restores with
            $this->requestProtectedModeEnable($operation, is_string($acceptKey) ? $acceptKey : '');
        } catch (Throwable $e) {
            // Cluster config is read on this path, so the request can fail before it is even
            // queued; leaving the state armed would hold the caller for the whole window over
            // a failure already known.
            $this->clearProtectedModeTest();
            $this->refuseProtectedModeTest($data->correlationId, 'enable request failed: ' . $e->getMessage());
        }
    }

    /**
     * Ends the driven operation into the verification window, unless the core would drop the request.
     *
     * Where a real operation ends, and for the same reason: nothing opens a system by finishing
     * its own work.
     *
     * The phase check names the two rows that are wrong on ANY node rather than demanding the
     * right one, because this row is not always the one the core judges by: on a cluster only the
     * leader writes active, so an initiator hosted on a follower reads activating for the whole
     * freeze and a check for active would refuse every leave it ever sent. Waiting for verifying
     * still works there - the leader broadcasts the window to its followers - so what is left to
     * catch locally is a leave with no freeze under it and a window already open, and the core's
     * own fail-closed check stays behind both.
     *
     * @param CommandRequestDTO $data Leave request
     * @param ProtectedModeRuntime $freeze This node's freeze row
     */
    private function leaveProtectedModeForTest(CommandRequestDTO $data, ProtectedModeRuntime $freeze): void
    {
        if (!$this->mayDriveProtectedModeTest($data, $freeze)) {
            return;
        }

        if (
            $freeze->phase === StateProtectedModeRuntime::PHASE_INACTIVE
            || $freeze->phase === StateProtectedModeRuntime::PHASE_VERIFYING
        ) {
            $this->refuseProtectedModeTest(
                $data->correlationId,
                "the mode is '{$freeze->phase}', so there is no operation of this node's to end",
            );

            return;
        }

        $this->armProtectedModeTest($data->correlationId, StateProtectedModeRuntime::PHASE_VERIFYING);

        try {
            $this->requestProtectedModeVerify();
        } catch (InvalidArgumentException $e) {
            $this->clearProtectedModeTest();
            $this->refuseProtectedModeTest($data->correlationId, 'verify request failed: ' . $e->getMessage());
        }
    }

    /**
     * Opens the system to everyone, unless this node's row says the core would drop the request.
     *
     * Deliberately not gated on the verification window, unlike the leave above: this is the one
     * lever that gets a node out of a freeze, and a teardown lifting after a failed assertion
     * cannot know which phase the run left behind.
     *
     * @param CommandRequestDTO $data Open request
     * @param ProtectedModeRuntime $freeze This node's freeze row
     */
    private function openProtectedModeForTest(CommandRequestDTO $data, ProtectedModeRuntime $freeze): void
    {
        if (!$this->mayDriveProtectedModeTest($data, $freeze)) {
            return;
        }

        $this->armProtectedModeTest($data->correlationId, StateProtectedModeRuntime::PHASE_INACTIVE);

        $this->requestProtectedModeDisable();
    }

    /**
     * Whether a freeze is here to drive and this agent is the one entitled to drive it.
     *
     * Authorized by initiator identity exactly as production authorizes it - there is no forced
     * lift here and none is wanted. A stand does not strand on that: the initiator is a
     * long-lived agent, so the open after a failed test arrives from the same agent and passes.
     * Refuses on this agent's behalf, so a caller reads a reason rather than a mute timeout.
     *
     * @param CommandRequestDTO $data Request being authorized
     * @param ProtectedModeRuntime $freeze This node's freeze row
     * @return bool True when the request may proceed
     */
    private function mayDriveProtectedModeTest(CommandRequestDTO $data, ProtectedModeRuntime $freeze): bool
    {
        if ($freeze->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->refuseProtectedModeTest($data->correlationId, 'no freeze is active here');

            return false;
        }

        if (!$this->isProtectedModeTestInitiator($freeze)) {
            $initiator = $freeze->initiatorAgentType ?? 'nobody';
            $this->refuseProtectedModeTest(
                $data->correlationId,
                "the freeze was initiated by '{$initiator}', not by this agent",
            );

            return false;
        }

        return true;
    }

    /**
     * Arms the wait for one accepted command.
     *
     * @param string $correlationId Correlation id of the request being awaited
     * @param string $awaitedPhase Phase whose arrival answers it; empty when the ready relay does
     */
    private function armProtectedModeTest(string $correlationId, string $awaitedPhase): void
    {
        $this->protectedModeTestCorrelationId = $correlationId;
        $this->protectedModeTestSince = microtime(true);
        $this->protectedModeTestAwaitedPhase = $awaitedPhase;
    }

    /**
     * Whether the freeze row names this very agent as its initiator.
     *
     * @param ProtectedModeRuntime $freeze This node's freeze row
     * @return bool True when this agent is the recorded initiator
     */
    private function isProtectedModeTestInitiator(ProtectedModeRuntime $freeze): bool
    {
        if ($freeze->initiatorAgentType !== $this->getType()) {
            return false;
        }

        $index = $this->getIndex();

        return $index === null
            ? $freeze->initiatorAgentIndex === null
            : $freeze->initiatorAgentIndex === (int)$index;
    }

    /**
     * Replies success with the phase the drive reached, and disarms the wait.
     *
     * The phase is passed in rather than read here: each caller knows what its own completion
     * signal means, and for enter that meaning is more reliable than the local row.
     *
     * @param string $phase Phase to report as the outcome of the drive
     */
    private function answerProtectedModeTest(string $phase): void
    {
        $correlationId = $this->protectedModeTestCorrelationId;
        if ($correlationId === null) {
            return;
        }

        $this->clearProtectedModeTest();

        $this->replyToCommand(CommandReplyDTO::ok($correlationId, [
            ProtectedModeCommandConstants::FIELD_PHASE => $phase,
        ]));
    }

    /**
     * Replies a refusal carrying its reason, so the caller never has to read a timeout.
     *
     * @param string $correlationId Correlation id of the request being refused
     * @param string $reason Human-readable reason
     */
    private function refuseProtectedModeTest(string $correlationId, string $reason): void
    {
        $this->replyToCommand(CommandReplyDTO::error($correlationId, $reason));
    }

    /**
     * Disarms the wait state.
     */
    private function clearProtectedModeTest(): void
    {
        $this->protectedModeTestCorrelationId = null;
        $this->protectedModeTestSince = 0.0;
        $this->protectedModeTestAwaitedPhase = '';
    }

    /**
     * @return ?ProtectedModeRuntime This worker's freeze row, or null when the mode is unmounted
     */
    private function protectedModeTestRow(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}

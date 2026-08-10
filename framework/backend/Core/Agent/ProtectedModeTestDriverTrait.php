<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
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
 * An agent that uses this answers `test:protected-mode:enter` and `test:protected-mode:leave`
 * by calling {@see AbstractAgent::requestProtectedModeEnable()} /
 * {@see AbstractAgent::requestProtectedModeDisable()} - the same requests a restore makes. No
 * state is forced and no second entry is opened, which is the point: the initiator identity
 * those requests record is what authorizes the later release and what the agent-start gate
 * lets through, so a synthetic entry would exercise a path production does not have.
 *
 * A trait rather than a base class because the two carriers share no ancestor but
 * {@see AbstractAgent}, and putting the commands there would hand a test-drive of the freeze
 * to every agent of every project - the same reasoning that made
 * {@see CommandChannelClientTrait} a trait.
 *
 * **The reply is a verdict, not an acknowledgement.** Both commands answer once the mode has
 * really moved: enter from {@see onProtectedModeReady()}, leave when this node's row is back
 * to inactive. That is what lets a test act on the next line instead of polling.
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

    /** @var bool Whether the awaited command is a leave (else an enter) */
    private bool $protectedModeTestLeaving = false;

    /**
     * Whether this command is one of the two this trait drives.
     *
     * @param string $command Command-channel wire name
     * @return bool True when {@see handleProtectedModeTestCommand()} owns it
     */
    protected function isProtectedModeTestCommand(string $command): bool
    {
        return $command === CliCommands::PROTECTED_MODE_TEST_ENTER
            || $command === CliCommands::PROTECTED_MODE_TEST_LEAVE;
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

        $this->leaveProtectedModeForTest($data, $freeze);
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
        if ($this->protectedModeTestCorrelationId === null || $this->protectedModeTestLeaving) {
            return;
        }

        $this->answerProtectedModeTest(StateProtectedModeRuntime::PHASE_ACTIVE);
    }

    /**
     * Finishes a pending leave, and expires either command whose window ran out.
     *
     * The carrier calls this from its own onTick. Leave has no ready relay to answer from -
     * the lift is observed as this node's row returning to inactive, which is also exactly the
     * moment a caller may load a page without racing the agents coming back up.
     */
    protected function tickProtectedModeTestDriver(): void
    {
        if ($this->protectedModeTestCorrelationId === null) {
            return;
        }

        $phase = $this->protectedModeTestRow()?->phase ?? StateProtectedModeRuntime::PHASE_INACTIVE;
        if ($this->protectedModeTestLeaving && $phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->answerProtectedModeTest($phase);

            return;
        }

        if ((microtime(true) - $this->protectedModeTestSince) < self::PROTECTED_MODE_TEST_WAIT_SECONDS) {
            return;
        }

        $waited = self::PROTECTED_MODE_TEST_WAIT_SECONDS;
        $target = $this->protectedModeTestLeaving ? 'return to inactive' : 'become ready';
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

        $this->protectedModeTestCorrelationId = $data->correlationId;
        $this->protectedModeTestSince = microtime(true);
        $this->protectedModeTestLeaving = false;

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
     * Releases the freeze, unless this node's row says the core would drop the request.
     *
     * Authorized by initiator identity exactly as production authorizes it - there is no forced
     * lift here and none is wanted. A stand does not strand on that: the initiator is a
     * long-lived agent, so the leave after a failed test arrives from the same agent and passes.
     *
     * @param CommandRequestDTO $data Leave request
     * @param ProtectedModeRuntime $freeze This node's freeze row
     */
    private function leaveProtectedModeForTest(CommandRequestDTO $data, ProtectedModeRuntime $freeze): void
    {
        if ($freeze->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->refuseProtectedModeTest($data->correlationId, 'no freeze is active here');

            return;
        }

        if (!$this->isProtectedModeTestInitiator($freeze)) {
            $initiator = $freeze->initiatorAgentType ?? 'nobody';
            $this->refuseProtectedModeTest(
                $data->correlationId,
                "the freeze was initiated by '{$initiator}', not by this agent",
            );

            return;
        }

        $this->protectedModeTestCorrelationId = $data->correlationId;
        $this->protectedModeTestSince = microtime(true);
        $this->protectedModeTestLeaving = true;

        $this->requestProtectedModeDisable();
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
        $this->protectedModeTestLeaving = false;
    }

    /**
     * @return ?ProtectedModeRuntime This worker's freeze row, or null when the mode is unmounted
     */
    private function protectedModeTestRow(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Drives the verification window from an operator's terminal, through the agent that froze the node.
 *
 * The three commands are what a human uses to end a destructive operation: mint a pass for a
 * verifier, open the system to everyone, or close it back for another attempt. They are NOT
 * test-only - nothing else opens a system that a restore left frozen, because
 * {@see AbstractAgent::requestProtectedModeVerify()} deliberately replaced the automatic lift.
 *
 * A trait rather than a base class for the reason {@see ProtectedModeTestDriverTrait} is one:
 * its carriers share no ancestor but {@see AbstractAgent}, and the freeze may only be driven by
 * the agent the row records as its initiator - so the commands belong to whichever agent owns
 * the destructive operation, not to every agent of every project.
 *
 * **Why the operator commands and the test-drive commands are different names.** A command
 * routes to exactly one agent type per project (the topology validator refuses a second owner),
 * and a project may hold two initiators: the one that runs real operations, and the test
 * driver's carrier. Sharing a name would hand the freeze of one to the other, which the
 * identity check would then refuse - so the test path has its own explicit open
 * ({@see CliCommands::PROTECTED_MODE_TEST_OPEN}) instead.
 *
 * **The reply is a verdict, not an acknowledgement.** Each command answers once the row has
 * really moved - the pass once its hash is on the row, open once the mode is inactive, close
 * once it is active again - so an operator reading the terminal is reading what happened, not
 * what was asked for. A refusal comes back as its reason, because the core drops a request from
 * the wrong agent or against the wrong phase by logging a warning and replying to nobody.
 *
 * **Nothing inside the system stores the clear pass.** It exists in the minting method's local
 * variable, in the reply that carries it to the operator's terminal, and afterwards in whatever
 * the verifier's browser puts it into - the row keeps only {@see hash()} of it
 * ({@see StateProtectedModeRuntime::$passHashes}), so it can be read back neither from a snapshot
 * nor from a reply to a later command. What it is NOT is a secret nobody can observe in transit:
 * a verifier presents it as a query parameter on the socket url
 * ({@see ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM}), which is the one place
 * admission can be decided, so any proxy in front of the daemon that logs request lines logs the
 * pass with them. That is the accepted shape of a credential which lasts minutes and which
 * closing the window voids outright, and it is written down here because a docblock claiming
 * otherwise is what an operator would plan around.
 */
trait ProtectedModeOperatorTrait
{
    /**
     * @var float Seconds this agent waits for the row to move before answering a refusal
     *
     * The innermost of three nested windows, exactly as {@see ProtectedModeTestDriverTrait}
     * sizes its own: the CLI gives up after {@see CommandChannelClientTrait}'s budget and the
     * channel releases a held request after {@see CommandClient}'s, so the operator reads this
     * agent's stated reason rather than either mute timeout.
     */
    private const float PROTECTED_MODE_OPERATOR_WAIT_SECONDS = 3.0;

    /**
     * @var int Byte length of a minted pass
     *
     * Hex-encoded, so the operator reads 48 characters. Long enough that guessing it is not a
     * strategy against a window that lasts minutes, short enough to be read down a phone line.
     */
    private const int PROTECTED_MODE_PASS_BYTES = 24;

    /** @var ?string Correlation id of the operator command being awaited, or null when idle */
    private ?string $protectedModeOperatorCorrelationId = null;

    /** @var float Microtime the awaited command was accepted at */
    private float $protectedModeOperatorSince = 0.0;

    /** @var string Phase whose arrival answers the awaited command; empty while a pass is awaited */
    private string $protectedModeOperatorAwaitedPhase = '';

    /** @var string Hash whose arrival on the row answers an awaited pass; empty otherwise */
    private string $protectedModeOperatorPassHash = '';

    /** @var ?string Clear pass handed back when its hash lands, or null when none is in flight */
    private ?string $protectedModeOperatorPass = null;

    /**
     * Whether this command is one of the three this trait drives.
     *
     * @param string $command Command-channel wire name
     * @return bool True when {@see handleProtectedModeOperatorCommand()} owns it
     */
    protected function isProtectedModeOperatorCommand(string $command): bool
    {
        return $command === CliCommands::PROTECTED_MODE_PASS
            || $command === CliCommands::PROTECTED_MODE_OPEN
            || $command === CliCommands::PROTECTED_MODE_CLOSE;
    }

    /**
     * Accepts one operator command, refusing outright anything the core would drop silently.
     *
     * @param CommandRequestDTO $data Command request routed to this agent
     */
    protected function handleProtectedModeOperatorCommand(CommandRequestDTO $data): void
    {
        if ($this->protectedModeOperatorCorrelationId !== null) {
            // One at a time: a second would overwrite the correlation id and strand the first
            // operator on the channel until their own timeout.
            $this->refuseProtectedModeOperator(
                $data->correlationId,
                'another protected-mode command is still in flight',
            );

            return;
        }

        $freeze = $this->protectedModeOperatorRow();
        if ($freeze === null) {
            $this->refuseProtectedModeOperator(
                $data->correlationId,
                'protected mode is not mounted on this node, so it cannot be driven here',
            );

            return;
        }

        if ($freeze->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            $this->refuseProtectedModeOperator($data->correlationId, 'no freeze is active here');

            return;
        }

        if (!$this->isProtectedModeOperatorInitiator($freeze)) {
            $initiator = $freeze->initiatorAgentType ?? 'nobody';
            $this->refuseProtectedModeOperator(
                $data->correlationId,
                "the freeze was initiated by '{$initiator}', not by this agent",
            );

            return;
        }

        if ($data->command === CliCommands::PROTECTED_MODE_OPEN) {
            $this->openProtectedModeForOperator($data);

            return;
        }

        // Both remaining commands act on a window that is open: a pass minted for a window
        // nobody opened would sit on the row waiting for one, and a close is the other exit
        // out of that same window.
        if ($freeze->phase !== StateProtectedModeRuntime::PHASE_VERIFYING) {
            $this->refuseProtectedModeOperator(
                $data->correlationId,
                "the mode is '{$freeze->phase}', not '" . StateProtectedModeRuntime::PHASE_VERIFYING . "'",
            );

            return;
        }

        if ($data->command === CliCommands::PROTECTED_MODE_PASS) {
            $this->mintProtectedModePass($data);

            return;
        }

        $this->closeProtectedModeForOperator($data);
    }

    /**
     * Answers the command in flight once the row shows what it asked for, and expires a wait that ran out.
     *
     * The carrier calls this from its own onTick. There is no ready relay to answer from here -
     * all three outcomes are observed as this node's row changing, which is also exactly the
     * moment the operator may act on the result.
     */
    protected function tickProtectedModeOperator(): void
    {
        if ($this->protectedModeOperatorCorrelationId === null) {
            return;
        }

        $freeze = $this->protectedModeOperatorRow();
        $phase = $freeze?->phase ?? StateProtectedModeRuntime::PHASE_INACTIVE;

        if ($this->protectedModeOperatorPassHash !== '') {
            if ($freeze !== null && in_array($this->protectedModeOperatorPassHash, $freeze->passHashes, true)) {
                $this->answerProtectedModeOperator($phase, $this->protectedModeOperatorPass);

                return;
            }
        } elseif ($phase === $this->protectedModeOperatorAwaitedPhase) {
            $this->answerProtectedModeOperator($phase, null);

            return;
        }

        if ((microtime(true) - $this->protectedModeOperatorSince) < self::PROTECTED_MODE_OPERATOR_WAIT_SECONDS) {
            return;
        }

        $waited = self::PROTECTED_MODE_OPERATOR_WAIT_SECONDS;
        $correlationId = $this->protectedModeOperatorCorrelationId;
        $expired = $this->protectedModeOperatorPassHash !== ''
            ? $this->passWaitExpiredReason($phase)
            : "protected mode did not reach '{$this->protectedModeOperatorAwaitedPhase}' within {$waited}s (phase: {$phase})";
        $this->clearProtectedModeOperator();
        $this->refuseProtectedModeOperator($correlationId, $expired);
    }

    /**
     * The refusal a pass that has not landed in time gets, which is not the same as one that failed.
     *
     * A mint travels agent -> worker -> daemon and is written to the row there, so a wait that runs
     * out has not refuted anything: the hash may be recorded a tick later, and then a pass exists
     * that admits a verifier while its only clear copy has already been dropped here. Nobody can
     * use it, and nobody can see it either - the snapshot reports a count, never a hash - so the
     * refusal says how to be rid of it rather than pretending the mint failed.
     *
     * @param string $phase Phase the row reads as the wait expires
     * @return string Reason text handed to the operator
     */
    private function passWaitExpiredReason(string $phase): string
    {
        $waited = self::PROTECTED_MODE_OPERATOR_WAIT_SECONDS;

        return "protected mode did not record the pass within {$waited}s (phase: {$phase}) — if it lands "
            . 'late it is a pass nobody holds; close the window and open it again to void every pass on the row';
    }

    /**
     * Mints one pass, sends its hash on, and holds the clear value until the row confirms it.
     *
     * The secure half of {@see RandomHelper} and no fallback: a pass minted from the
     * pseudorandom source would be guessable, and nothing outside could tell it from a real one
     * (HIL-568). A source that refuses is therefore reported to the operator, who can try again.
     *
     * @param CommandRequestDTO $data Pass request
     */
    private function mintProtectedModePass(CommandRequestDTO $data): void
    {
        try {
            $pass = RandomHelper::secureHex(self::PROTECTED_MODE_PASS_BYTES);
        } catch (RandomException $e) {
            $this->refuseProtectedModeOperator(
                $data->correlationId,
                'the secure random source refused, and a pass must never ride the pseudorandom '
                . "fallback: {$e->getMessage()}",
            );

            return;
        }

        $passHash = hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, $pass);

        $this->armProtectedModeOperator($data->correlationId, '');
        $this->protectedModeOperatorPassHash = $passHash;
        $this->protectedModeOperatorPass = $pass;

        try {
            $this->requestProtectedModePass($passHash);
        } catch (InvalidArgumentException $e) {
            $this->clearProtectedModeOperator();
            $this->refuseProtectedModeOperator($data->correlationId, 'pass request failed: ' . $e->getMessage());
        }
    }

    /**
     * Opens the system to everyone, ending the window and voiding every pass.
     *
     * Deliberately not gated on the verifying phase, unlike its two siblings: this is the one
     * lever that gets a node out of a freeze, and an operator holding a system stuck mid-restore
     * must not be told to reach the verification window first.
     *
     * @param CommandRequestDTO $data Open request
     */
    private function openProtectedModeForOperator(CommandRequestDTO $data): void
    {
        $this->armProtectedModeOperator($data->correlationId, StateProtectedModeRuntime::PHASE_INACTIVE);

        $this->requestProtectedModeDisable();
    }

    /**
     * Closes the system back from the window, so another destructive operation may run.
     *
     * @param CommandRequestDTO $data Close request
     */
    private function closeProtectedModeForOperator(CommandRequestDTO $data): void
    {
        $this->armProtectedModeOperator($data->correlationId, StateProtectedModeRuntime::PHASE_ACTIVE);

        try {
            $this->requestProtectedModeRefreeze();
        } catch (InvalidArgumentException $e) {
            $this->clearProtectedModeOperator();
            $this->refuseProtectedModeOperator($data->correlationId, 'refreeze request failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether the freeze row names this very agent as its initiator.
     *
     * @param ProtectedModeRuntime $freeze This node's freeze row
     * @return bool True when this agent is the recorded initiator
     */
    private function isProtectedModeOperatorInitiator(ProtectedModeRuntime $freeze): bool
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
     * Arms the wait for one accepted command.
     *
     * @param string $correlationId Correlation id of the request being awaited
     * @param string $awaitedPhase Phase whose arrival answers it; empty when a pass is awaited
     */
    private function armProtectedModeOperator(string $correlationId, string $awaitedPhase): void
    {
        $this->protectedModeOperatorCorrelationId = $correlationId;
        $this->protectedModeOperatorSince = microtime(true);
        $this->protectedModeOperatorAwaitedPhase = $awaitedPhase;
        $this->protectedModeOperatorPassHash = '';
        $this->protectedModeOperatorPass = null;
    }

    /**
     * Replies success with the phase the row reached, and disarms the wait.
     *
     * @param string $phase Phase to report as the outcome
     * @param ?string $pass Clear pass to hand back, or null when the command minted none
     */
    private function answerProtectedModeOperator(string $phase, ?string $pass): void
    {
        $correlationId = $this->protectedModeOperatorCorrelationId;
        if ($correlationId === null) {
            return;
        }

        $this->clearProtectedModeOperator();

        $payload = [ProtectedModeCommandConstants::FIELD_PHASE => $phase];
        if ($pass !== null) {
            $payload[ProtectedModeCommandConstants::FIELD_PASS] = $pass;
        }

        $this->replyToCommand(CommandReplyDTO::ok($correlationId, $payload));
    }

    /**
     * Replies a refusal carrying its reason, so the operator never has to read a timeout.
     *
     * @param string $correlationId Correlation id of the request being refused
     * @param string $reason Human-readable reason
     */
    private function refuseProtectedModeOperator(string $correlationId, string $reason): void
    {
        $this->replyToCommand(CommandReplyDTO::error($correlationId, $reason));
    }

    /**
     * Disarms the wait state, dropping the clear pass with it.
     */
    private function clearProtectedModeOperator(): void
    {
        $this->protectedModeOperatorCorrelationId = null;
        $this->protectedModeOperatorSince = 0.0;
        $this->protectedModeOperatorAwaitedPhase = '';
        $this->protectedModeOperatorPassHash = '';
        $this->protectedModeOperatorPass = null;
    }

    /**
     * @return ?ProtectedModeRuntime This worker's freeze row, or null when the mode is unmounted
     */
    private function protectedModeOperatorRow(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}

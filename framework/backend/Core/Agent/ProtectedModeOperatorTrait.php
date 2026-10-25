<?php

declare(strict_types=1);

namespace Hilos\Core\Agent;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlDoneSignalData;
use Hilos\ProtectedMode\DTO\MaintenanceRestoreControlSignalData;
use Hilos\ProtectedMode\ProtectedModeAdmissionConstants;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
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
 * The three operator commands are what a human uses to end a destructive operation: mint a pass
 * for a verifier, open the system to everyone, or close it back for another attempt. They are NOT
 * test-only - nothing else opens a system that a restore left frozen, because
 * {@see AbstractAgent::requestProtectedModeVerify()} deliberately replaced the automatic lift. Two
 * further names reach the same handler for the test path's mint and its close (see below).
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
 * ({@see CliCommands::PROTECTED_MODE_TEST_OPEN}), its own mint
 * ({@see CliCommands::PROTECTED_MODE_TEST_PASS}) and its own close
 * ({@see CliCommands::PROTECTED_MODE_TEST_CLOSE}) instead. Different names, one handler: the mint
 * and the close below each fire on either of their two names, so there is a single minting path,
 * a single refreeze path, a single identity check and a single wait for the row to move.
 *
 * **The reply is a verdict, not an acknowledgement.** Each command answers once the row has
 * really moved - the pass once its hash is on the row, open once the mode is inactive, close
 * once it is active again - so an operator reading the terminal is reading what happened, not
 * what was asked for. A refusal comes back as its reason, because the core drops a request from
 * the wrong agent or against the wrong phase by logging a warning and replying to nobody.
 *
 * **One slot serves the terminal and the browser.** {@see beginProtectedModeRestoreControl()}
 * is the browser entrance: the same mint, the same open and the same close, addressed by the
 * ask instead of a command correlation id. The ask waits in this agent's own fields. It is
 * private to this process — the initiator that was asked is the one that answers — so it is
 * not written to a runtime row. A second arrival, of either kind, is refused and leaves the
 * first addressee armed.
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
 *
 * **The key is now the second way in, not the only one (HIL-643).** A circle of named people is
 * kept from the admin surface, photographed at the freeze, and let into the verification window
 * with the tabs they already have open ({@see VerifierCircleSnapshot}). The key stayed rather than
 * being replaced by it, and deliberately: the circle admits only somebody who was signed in at the
 * moment the freeze started, so anybody else - a colleague reached by phone, an operator on a
 * machine they had not logged in on - has nothing but a key. It is the emergency entrance, and an
 * emergency entrance that answers to no list is the point of one.
 *
 * **How long it waits for the row, and why that number is not declared here.** This agent's
 * window is {@see CommandChannelWindows::AGENT_WAIT_SECONDS}, the same one
 * {@see ProtectedModeTestDriverTrait} waits out. Three windows are nested, but there are TWO
 * chains, not one: production runs agent -> CLI process ({@see CommandChannelClientTrait}) ->
 * channel ({@see CommandClient}), the test stand runs agent -> e2e client -> channel. This
 * window is the innermost of BOTH, which is what lets an operator read the reason worded below
 * instead of whichever middle link would otherwise have timed out without one.
 */
trait ProtectedModeOperatorTrait
{
    /**
     * @var int Byte length of a minted pass
     *
     * Hex-encoded, so the operator reads 48 characters. Long enough that guessing it is not a
     * strategy against a window that lasts minutes, short enough to be read down a phone line.
     */
    private const int PROTECTED_MODE_PASS_BYTES = 24;

    /** @var ?string Correlation id of the operator command being awaited, or null when the slot holds a browser ask or is idle */
    private ?string $protectedModeOperatorCorrelationId = null;

    /**
     * Browser ask this agent is waiting to answer, or null when the slot holds a CLI command or is idle.
     *
     * Local to this initiator for the span of one control: no other worker reads the wait, and it
     * dies with the process. The runtime row is the freeze, not the operator's pending reply.
     */
    private ?MaintenanceRestoreControlSignalData $protectedModeOperatorAsk = null;

    /** @var float Microtime the awaited command was accepted at */
    private float $protectedModeOperatorSince = 0.0;

    /** @var string Phase whose arrival answers the awaited command; empty while a pass is awaited */
    private string $protectedModeOperatorAwaitedPhase = '';

    /** @var string Hash whose arrival on the row answers an awaited pass; empty otherwise */
    private string $protectedModeOperatorPassHash = '';

    /** @var ?string Clear pass handed back when its hash lands, or null when none is in flight */
    private ?string $protectedModeOperatorPass = null;

    /**
     * Whether this command is one of the five this trait drives.
     *
     * The mint and the close each answer to two names: the operator's and the test path's. Which
     * of them ever reaches a given carrier is decided by that carrier's AGENT_COMMANDS
     * declaration, because a command routes to exactly one agent type per project - so
     * recognizing both here costs nothing and duplicates no minting or refreeze path.
     *
     * @param string $command Command-channel wire name
     * @return bool True when {@see handleProtectedModeOperatorCommand()} owns it
     */
    protected function isProtectedModeOperatorCommand(string $command): bool
    {
        return $this->isProtectedModePassCommand($command)
            || $this->isProtectedModeCloseCommand($command)
            || $command === CliCommands::PROTECTED_MODE_OPEN;
    }

    /**
     * Whether this command asks for a mint, under either of the two names that do.
     *
     * @param string $command Command-channel wire name
     * @return bool True when the command mints one pass
     */
    private function isProtectedModePassCommand(string $command): bool
    {
        return $command === CliCommands::PROTECTED_MODE_PASS
            || $command === CliCommands::PROTECTED_MODE_TEST_PASS;
    }

    /**
     * Whether this command asks for the close, under either of the two names that do.
     *
     * @param string $command Command-channel wire name
     * @return bool True when the command closes the window back into a full freeze
     */
    private function isProtectedModeCloseCommand(string $command): bool
    {
        return $command === CliCommands::PROTECTED_MODE_CLOSE
            || $command === CliCommands::PROTECTED_MODE_TEST_CLOSE;
    }

    /**
     * Accepts one operator command, refusing outright anything the core would drop silently.
     *
     * A browser ask already waiting in the same slot is left armed: this command is the one refused.
     *
     * @param CommandRequestDTO $data Command request routed to this agent
     * @throws InvalidArgumentException When the queued protected-mode release cannot be named
     * @throws InvalidFormatException When the shared reply cannot build a browser outcome frame
     */
    protected function handleProtectedModeOperatorCommand(CommandRequestDTO $data): void
    {
        if ($this->protectedModeOperatorAwaiting()) {
            $this->replyProtectedModeRefusal(
                $data->correlationId,
                null,
                'another protected-mode command is still in flight',
            );

            return;
        }

        $freeze = $this->protectedModeOperatorRow();
        $refusal = $this->protectedModeDriveRefusal($freeze);
        if ($refusal !== null) {
            $this->refuseProtectedModeOperator($data->correlationId, $refusal);

            return;
        }
        if ($freeze === null) {
            return;
        }

        if (
            $data->command === CliCommands::PROTECTED_MODE_TEST_PASS
            && $freeze->operation === StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE
        ) {
            $this->refuseProtectedModeOperator($data->correlationId, 'the test pass cannot drive manual maintenance');

            return;
        }

        if (
            $data->command === CliCommands::PROTECTED_MODE_TEST_CLOSE
            && $freeze->entryMode === StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW
        ) {
            $this->refuseProtectedModeOperator($data->correlationId, 'a direct verification window cannot be refrozen');

            return;
        }

        $this->dispatchProtectedModeOperatorControl(
            $freeze,
            $this->protectedModeOperatorControl($data->command),
            $data->correlationId,
            null,
            $data->command,
        );
    }

    /**
     * Accepts one browser restore control into the same slot as an operator command.
     *
     * The ask is the addressee. Nothing here builds a command request or loops one back to this
     * agent. A slot that is already waiting refuses this ask and stays armed for the first one.
     *
     * @param MaintenanceRestoreControlSignalData $ask Restore control for one server-resolved browser
     * @throws InvalidArgumentException When the queued protected-mode release cannot be named
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    protected function beginProtectedModeRestoreControl(MaintenanceRestoreControlSignalData $ask): void
    {
        if ($this->protectedModeOperatorAwaiting()) {
            $this->replyProtectedModeRefusal(
                null,
                $ask,
                'another protected-mode command is still in flight',
            );

            return;
        }

        $freeze = $this->protectedModeOperatorRow();
        $refusal = $this->protectedModeDriveRefusal($freeze);
        if ($refusal !== null) {
            $this->replyProtectedModeRefusal(null, $ask, $refusal);

            return;
        }
        if ($freeze === null) {
            return;
        }

        $this->dispatchProtectedModeOperatorControl(
            $freeze,
            $ask->control,
            null,
            $ask,
            $ask->control,
        );
    }

    /**
     * Answers the control in flight once the row shows what it asked for, and expires a wait that ran out.
     *
     * Idle is both addressees empty. The one in flight is copied and cleared before the reply, so a
     * hash or a phase that lands late cannot close the next wait.
     *
     * The carrier calls this from its own onTick. There is no ready relay to answer from here -
     * all three outcomes are observed as this node's row changing, which is also exactly the
     * moment the operator may act on the result.
     *
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    protected function tickProtectedModeOperator(): void
    {
        if (!$this->protectedModeOperatorAwaiting()) {
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

        if ((microtime(true) - $this->protectedModeOperatorSince) < CommandChannelWindows::AGENT_WAIT_SECONDS) {
            return;
        }

        $waited = CommandChannelWindows::AGENT_WAIT_SECONDS;
        $correlationId = $this->protectedModeOperatorCorrelationId;
        $ask = $this->protectedModeOperatorAsk;
        $expired = $this->protectedModeOperatorPassHash !== ''
            ? $this->passWaitExpiredReason($phase)
            : "protected mode did not reach '{$this->protectedModeOperatorAwaitedPhase}' within {$waited}s (phase: {$phase})";
        $this->clearProtectedModeOperator();
        $this->replyProtectedModeRefusal($correlationId, $ask, $expired);
    }

    /**
     * Asks for the freeze to lift. A carrier that owes restored logins may hold the request
     * and must send it later itself; the default sends it now.
     *
     * @throws InvalidArgumentException When the queued protected-mode release cannot be named
     */
    protected function requestProtectedModeRelease(): void
    {
        $this->requestProtectedModeDisable();
    }

    /**
     * Drops a held protected-mode release.
     *
     * A carrier holding a parked lift drops it when an operator command closes the window again,
     * so that a lift requested before the close does not reopen a node frozen after it (HIL-1058).
     * The default drops nothing.
     */
    protected function withdrawProtectedModeRelease(): void
    {
    }

    /**
     * Whether an operator close has been accepted and is waiting for the row to reach active.
     *
     * True for a command-channel close and for a browser close: both arm the same phase, and a
     * reopen that arrives while either is in flight must not win.
     *
     * @return bool True while a close is in flight
     */
    protected function isProtectedModeCloseInFlight(): bool
    {
        return $this->protectedModeOperatorAwaiting()
            && $this->protectedModeOperatorAwaitedPhase === StateProtectedModeRuntime::PHASE_ACTIVE;
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
        $waited = CommandChannelWindows::AGENT_WAIT_SECONDS;

        return "protected mode did not record the pass within {$waited}s (phase: {$phase}) — if it lands "
            . 'late it is a pass nobody holds; close the window and open it again to void every pass on the row';
    }

    /**
     * Whether a CLI command or a browser ask is already waiting on the row.
     *
     * @return bool True when either addressee is set
     */
    private function protectedModeOperatorAwaiting(): bool
    {
        return $this->protectedModeOperatorCorrelationId !== null
            || $this->protectedModeOperatorAsk !== null;
    }

    /**
     * The refusal a freeze that cannot be driven gets, before any control is told apart.
     *
     * @param ?ProtectedModeRuntime $freeze This node's freeze row, or null when the mode is unmounted
     * @return ?string Refusal sentence, or null when the row may be driven
     */
    private function protectedModeDriveRefusal(?ProtectedModeRuntime $freeze): ?string
    {
        if ($freeze === null) {
            return 'protected mode is not mounted on this node, so it cannot be driven here';
        }

        if ($freeze->phase === StateProtectedModeRuntime::PHASE_INACTIVE) {
            return 'no freeze is active here';
        }

        if (!$this->isProtectedModeOperatorInitiator($freeze)) {
            $initiator = $freeze->initiatorAgentType ?? 'nobody';

            return "the freeze was initiated by '{$initiator}', not by this agent";
        }

        return null;
    }

    /**
     * Maps an operator command onto the control both entrances share.
     *
     * @param string $command Command-channel wire name
     * @return ?string pass, open or close, or null when this handler does not drive the name
     */
    private function protectedModeOperatorControl(string $command): ?string
    {
        if ($command === CliCommands::PROTECTED_MODE_OPEN) {
            return MaintenanceRestoreControlSignalData::CONTROL_OPEN;
        }

        if ($this->isProtectedModePassCommand($command)) {
            return MaintenanceRestoreControlSignalData::CONTROL_PASS;
        }

        if ($this->isProtectedModeCloseCommand($command)) {
            return MaintenanceRestoreControlSignalData::CONTROL_CLOSE;
        }

        return null;
    }

    /**
     * The refusal a pass or a close gets when the window is not open.
     *
     * Open is not asked: it is the lever that leaves a freeze from any phase.
     *
     * @param ProtectedModeRuntime $freeze This node's freeze row
     * @return ?string Refusal sentence, or null when the phase is verifying
     */
    private function protectedModeWindowRefusal(ProtectedModeRuntime $freeze): ?string
    {
        if ($freeze->phase === StateProtectedModeRuntime::PHASE_VERIFYING) {
            return null;
        }

        return "the mode is '{$freeze->phase}', not '" . StateProtectedModeRuntime::PHASE_VERIFYING . "'";
    }

    /**
     * Runs one accepted control for whichever addressee is waiting on it.
     *
     * Exactly one of the correlation id and the ask is set. The unknown-name branch is unreachable
     * while the recognizer lists exactly the names mapped above, and written out anyway so that
     * adding a sixth name cannot make this method fall off the end in silence: a command accepted
     * and never answered strands its CLI on the channel until that side's own timeout.
     *
     * @param ProtectedModeRuntime $freeze Freeze row the gate already accepted
     * @param ?string $control pass, open or close, or null when the command is not driven here
     * @param ?string $correlationId CLI correlation id, or null when a browser ask is the addressee
     * @param ?MaintenanceRestoreControlSignalData $ask Browser ask, or null when a CLI command is the addressee
     * @param string $unknownName Command or control named in the refusal when nothing here drives it
     * @throws InvalidArgumentException When the queued protected-mode release cannot be named
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    private function dispatchProtectedModeOperatorControl(
        ProtectedModeRuntime $freeze,
        ?string $control,
        ?string $correlationId,
        ?MaintenanceRestoreControlSignalData $ask,
        string $unknownName,
    ): void {
        if ($control === MaintenanceRestoreControlSignalData::CONTROL_OPEN) {
            $this->openProtectedModeForOperator($correlationId, $ask);

            return;
        }

        // Every remaining control acts on a window that is open: a pass minted for a window
        // nobody opened would sit on the row waiting for one, and a close is the other exit
        // out of that same window.
        $windowRefusal = $this->protectedModeWindowRefusal($freeze);
        if ($windowRefusal !== null) {
            $this->replyProtectedModeRefusal($correlationId, $ask, $windowRefusal);

            return;
        }

        if ($control === MaintenanceRestoreControlSignalData::CONTROL_PASS) {
            $this->mintProtectedModePass($correlationId, $ask);

            return;
        }

        if ($control === MaintenanceRestoreControlSignalData::CONTROL_CLOSE) {
            $this->closeProtectedModeForOperator($correlationId, $ask);

            return;
        }

        $this->replyProtectedModeRefusal($correlationId, $ask, "'{$unknownName}' is not driven here");
    }

    /**
     * Mints one pass, sends its hash on, and holds the clear value until the row confirms it.
     *
     * The secure half of {@see RandomHelper} and no fallback: a pass minted from the
     * pseudorandom source would be guessable, and nothing outside could tell it from a real one
     * (HIL-568). A source that refuses is therefore reported to the operator, who can try again.
     *
     * @param ?string $correlationId CLI correlation id, or null when a browser ask is the addressee
     * @param ?MaintenanceRestoreControlSignalData $ask Browser ask, or null when a CLI command is the addressee
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    private function mintProtectedModePass(
        ?string $correlationId,
        ?MaintenanceRestoreControlSignalData $ask,
    ): void {
        try {
            $pass = $this->createProtectedModePass();
        } catch (RandomException $e) {
            $this->replyProtectedModeRefusal(
                $correlationId,
                $ask,
                'the secure random source refused, and a pass must never ride the pseudorandom '
                . "fallback: {$e->getMessage()}",
            );

            return;
        }

        $passHash = $this->hashProtectedModePass($pass);

        $this->armProtectedModeOperator($correlationId, $ask, '');
        $this->protectedModeOperatorPassHash = $passHash;
        $this->protectedModeOperatorPass = $pass;

        try {
            $this->requestProtectedModePass($passHash);
        } catch (InvalidArgumentException $e) {
            $this->clearProtectedModeOperator();
            $this->replyProtectedModeRefusal($correlationId, $ask, 'pass request failed: ' . $e->getMessage());
        }
    }

    /**
     * Draws a pass from the same secure source for every protected-mode caller.
     *
     * @return string Clear pass held only until its hash appears on the runtime row
     * @throws RandomException When the secure source refuses entropy
     */
    protected function createProtectedModePass(): string
    {
        return RandomHelper::secureHex(self::PROTECTED_MODE_PASS_BYTES);
    }

    /**
     * @param string $pass Clear pass to hash before sending it to the daemon
     * @return string Hash stored on the protected-mode runtime row
     */
    protected function hashProtectedModePass(string $pass): string
    {
        return hash(ProtectedModeAdmissionConstants::PASS_HASH_ALGO, $pass);
    }

    /**
     * Opens the system to everyone, ending the window and voiding every pass.
     *
     * Deliberately not gated on the verifying phase, unlike its two siblings: this is the one
     * lever that gets a node out of a freeze, and an operator holding a system stuck mid-restore
     * must not be told to reach the verification window first.
     *
     * @param ?string $correlationId CLI correlation id, or null when a browser ask is the addressee
     * @param ?MaintenanceRestoreControlSignalData $ask Browser ask, or null when a CLI command is the addressee
     * @throws InvalidArgumentException When the queued protected-mode release cannot be named
     */
    private function openProtectedModeForOperator(
        ?string $correlationId,
        ?MaintenanceRestoreControlSignalData $ask,
    ): void {
        $this->armProtectedModeOperator($correlationId, $ask, StateProtectedModeRuntime::PHASE_INACTIVE);

        $this->requestProtectedModeRelease();
    }

    /**
     * Closes the system back from the window, so another destructive operation may run.
     *
     * Answered once the row reads active, which since HIL-1128 means the agents the window brought
     * back have stopped again on every node: the close walks back through activating like an entry.
     *
     * @param ?string $correlationId CLI correlation id, or null when a browser ask is the addressee
     * @param ?MaintenanceRestoreControlSignalData $ask Browser ask, or null when a CLI command is the addressee
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    private function closeProtectedModeForOperator(
        ?string $correlationId,
        ?MaintenanceRestoreControlSignalData $ask,
    ): void {
        $this->armProtectedModeOperator($correlationId, $ask, StateProtectedModeRuntime::PHASE_ACTIVE);

        try {
            $this->requestProtectedModeRefreeze();
            $this->withdrawProtectedModeRelease();
        } catch (InvalidArgumentException $e) {
            $this->clearProtectedModeOperator();
            $this->replyProtectedModeRefusal($correlationId, $ask, 'refreeze request failed: ' . $e->getMessage());
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
     * Arms the wait for one accepted control.
     *
     * Exactly one addressee is set: the correlation id of a CLI command, or the browser ask.
     *
     * @param ?string $correlationId CLI correlation id, or null when a browser ask is the addressee
     * @param ?MaintenanceRestoreControlSignalData $ask Browser ask, or null when a CLI command is the addressee
     * @param string $awaitedPhase Phase whose arrival answers it; empty when a pass is awaited
     */
    private function armProtectedModeOperator(
        ?string $correlationId,
        ?MaintenanceRestoreControlSignalData $ask,
        string $awaitedPhase,
    ): void {
        $this->protectedModeOperatorCorrelationId = $correlationId;
        $this->protectedModeOperatorAsk = $ask;
        $this->protectedModeOperatorSince = microtime(true);
        $this->protectedModeOperatorAwaitedPhase = $awaitedPhase;
        $this->protectedModeOperatorPassHash = '';
        $this->protectedModeOperatorPass = null;
    }

    /**
     * Replies success to the addressee copied off the slot, and disarms the wait first.
     *
     * @param string $phase Phase to report as the outcome
     * @param ?string $pass Clear pass to hand back, or null when the control minted none
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    private function answerProtectedModeOperator(string $phase, ?string $pass): void
    {
        $correlationId = $this->protectedModeOperatorCorrelationId;
        $ask = $this->protectedModeOperatorAsk;
        if ($correlationId === null && $ask === null) {
            return;
        }

        $this->clearProtectedModeOperator();

        if ($correlationId !== null) {
            $payload = [ProtectedModeCommandConstants::FIELD_PHASE => $phase];
            if ($pass !== null) {
                $payload[ProtectedModeCommandConstants::FIELD_PASS] = $pass;
            }

            $this->replyToCommand(CommandReplyDTO::ok($correlationId, $payload));

            return;
        }

        if ($ask !== null) {
            $this->replyProtectedModeRestoreControl($ask, null, $pass);
        }
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
     * Refuses whichever addressee this attempt belongs to, without touching the one already armed.
     *
     * @param ?string $correlationId CLI correlation id of this attempt, or null for a browser ask
     * @param ?MaintenanceRestoreControlSignalData $ask Browser ask of this attempt, or null for a CLI command
     * @param string $reason Human-readable reason
     * @throws InvalidFormatException When the browser outcome frame cannot be built
     */
    private function replyProtectedModeRefusal(
        ?string $correlationId,
        ?MaintenanceRestoreControlSignalData $ask,
        string $reason,
    ): void {
        if ($correlationId !== null) {
            $this->refuseProtectedModeOperator($correlationId, $reason);

            return;
        }

        if ($ask !== null) {
            $this->replyProtectedModeRestoreControl($ask, ActionRefusal::said($reason), null);
        }
    }

    /**
     * Sends the browser outcome to the ask's own reply signal.
     *
     * A successful pass carries the clear code. Open, close and every refusal carry none, and the
     * code is not written to the journal.
     *
     * @param MaintenanceRestoreControlSignalData $ask Ask being answered
     * @param ?ActionRefusal $refusal Why the control was refused, or null when it went through
     * @param ?string $pass Clear code on a successful pass, or null otherwise
     * @throws InvalidArgumentException When the reply signal cannot be named
     * @throws InvalidFormatException When the outcome frame disagrees with its code
     */
    private function replyProtectedModeRestoreControl(
        MaintenanceRestoreControlSignalData $ask,
        ?ActionRefusal $refusal,
        ?string $pass,
    ): void {
        $this->sendToAgent(
            $ask->replySignal,
            new MaintenanceRestoreControlDoneSignalData(
                HandoverAnswerSignalData::to($ask, $refusal),
                $pass,
            ),
        );
    }

    /**
     * Disarms the wait state, dropping the clear pass and both addressees with it.
     */
    private function clearProtectedModeOperator(): void
    {
        $this->protectedModeOperatorCorrelationId = null;
        $this->protectedModeOperatorAsk = null;
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

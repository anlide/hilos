<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Closure;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\ProtectedModeOperatorTrait;
use Hilos\Core\Agent\ProtectedModeTestDriverTrait;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use LogicException;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\ProtectedMode\ManualMaintenanceOutcome;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Random\RandomException;
use Throwable;

/**
 * AbstractHilosIndexAgent - Abstract agent for Hilos dashboard, settings, i18n, and non-logs admin pages.
 *
 * Projects must extend this class to provide a concrete agent for Hilos index-scoped pages.
 * Logs overview uses {@see AbstractHilosLogsAgent} separately.
 *
 * It was also the periodic owner of the channel delivery journal until HIL-771. The prune
 * deletes rows from that journal, so it belongs to whoever owns it, and since HIL-771 that is
 * {@see AbstractNotificationsLibraryAgent} - which took the schedule, the idempotence and the
 * skip-when-unconfigured whole.
 */
abstract class AbstractHilosIndexAgent extends AbstractHilosAgent
{
    use ProtectedModeOperatorTrait;
    use ProtectedModeTestDriverTrait {
        onProtectedModeReady as private onProtectedModeTestReady;
        onProtectedModeRefused as private onProtectedModeTestRefused;
    }

    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /**
     * The OAuth provider rows, which the provider page this agent serves writes (HIL-286), and
     * the verifier circle.
     *
     * The page's actions run in this agent, and the rows are what an administrator entered on
     * that page and nowhere else, so their writer is this agent and no library stands between:
     * there is no other process that brings a provider row into being or edits one. Every
     * other process reads them process-wide ({@see HilosDbContext::processWideReadCollections()}).
     *
     * The circle is written by the actions of the pages this agent serves too - a person is named
     * and taken out in the maintenance section (HIL-1120, HIL-1121) - and it is claimed here
     * rather than by each project because its table is in every installation that can freeze (HIL-1118):
     * a project that builds a runtime context is refused by its own unit test without the
     * migration, so there is nothing left to ask it before the claim is legal.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        HilosDbContext::oauthProviders => TruthSourceOperation::BY_KIND,
        HilosDbContext::verifierCircle => TruthSourceOperation::BY_KIND,
    ];

    /**
     * The commands this agent answers, and the reason each of them is the index agent's.
     *
     * The test-only emit is NOT among them any more (HIL-771). It landed here because there
     * was no notification-owned agent to put it on - {@see HilosNotifier} was a worker seam
     * any process called - and {@see AbstractNotificationsLibraryAgent} is now that agent, so
     * the command sits beside the tables it writes.
     *
     * The protected-mode names (HIL-344, HIL-481, HIL-616, HIL-704) ride the same inheritance for the
     * same reason - chat, tasks and polls get a freeze they can drive by extending this
     * class alone. The inspector is not among them: it is answered by the master, because a
     * freeze stops every agent but the initiator. The operator commands are not either: they
     * belong to the agent that runs real operations, and a command routes to exactly one agent
     * type. The fourth name is the test path's mint: it is answered by the operator trait this
     * class also carries, because a driven window otherwise has no way to produce the code its
     * maintenance screen now asks for - and the operator's own three names stay where they are.
     * The fifth (HIL-704) is that trait's other half, the test path's close: the window has two
     * exits, and without it nothing ever drove the one that freezes the node again instead of
     * opening it to everyone.
     *
     * The channel echo (HIL-729) rides it for the plainest reason of the lot: it proves the
     * command round-trip and nothing else, so the answer is the same wherever it is asked, and
     * an installation with no project agent of its own would otherwise have no way to ask.
     *
     * The admin grant pair is NOT here any more (HIL-729): what it writes ends in a person's
     * open tabs being told, and only the sessions library knows which sockets those are, so
     * {@see AbstractSessionsLibraryAgent} answers it now.
     */
    public const array AGENT_COMMANDS = [
        CliCommands::COMMAND_TEST_ECHO,
        CliCommands::PROTECTED_MODE_TEST_ENTER,
        CliCommands::PROTECTED_MODE_TEST_LEAVE,
        CliCommands::PROTECTED_MODE_TEST_OPEN,
        CliCommands::PROTECTED_MODE_TEST_PASS,
        CliCommands::PROTECTED_MODE_TEST_CLOSE,
    ];

    private const string MANUAL_ENABLE = 'enable';
    private const string MANUAL_DISABLE = 'disable';
    private const string MANUAL_PASS = 'pass';

    /** @var ?Closure(ManualMaintenanceOutcome):void Reply held until the requested state is observed */
    private ?Closure $manualMaintenanceReply = null;

    /** @var ?string One of the manual action constants while a reply is pending */
    private ?string $manualMaintenanceAction = null;

    /** @var float Time at which the pending manual request was queued */
    private float $manualMaintenanceSince = 0.0;

    /** @var string Hash whose arrival confirms a pending pass */
    private string $manualMaintenancePassHash = '';

    /** @var ?string Clear pass held only while its hash is pending */
    private ?string $manualMaintenancePass = null;

    /**
     * Starts a direct verification window for manual maintenance.
     *
     * A browser caller supplies its accept key and session hash. A CLI caller supplies
     * the empty accept key and null session hash, naming no initiating browser.
     *
     * @param string $acceptKey Browser connection's accept key, or empty for CLI
     * @param ?string $sessionHash Browser session token hash, or null for CLI
     * @param Closure(ManualMaintenanceOutcome):void $reply One result after confirmation or refusal
     */
    protected function enableManualMaintenance(string $acceptKey, ?string $sessionHash, Closure $reply): void
    {
        if ($this->protectedModeRequestInFlight()) {
            $reply(ManualMaintenanceOutcome::refused('another protected-mode request is still in flight'));

            return;
        }

        if (($acceptKey === '') !== ($sessionHash === null) || $sessionHash === '') {
            $reply(ManualMaintenanceOutcome::refused('a browser entry needs both its accept key and session hash'));

            return;
        }

        $freeze = $this->manualMaintenanceRow();
        if ($freeze === null) {
            $reply(ManualMaintenanceOutcome::refused('protected mode is not mounted on this node'));

            return;
        }

        if ($freeze->phase !== StateProtectedModeRuntime::PHASE_INACTIVE) {
            $reason = $freeze->operation === StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE
                ? 'manual maintenance is already active'
                : "protected mode is already active for '{$freeze->operation}'";
            $reply(ManualMaintenanceOutcome::refused($reason));

            return;
        }

        $this->armManualMaintenance(self::MANUAL_ENABLE, $reply);

        try {
            $this->requestProtectedModeEnable(
                StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
                $acceptKey,
                $sessionHash,
                StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW,
            );
        } catch (Throwable $e) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::refused('enable request failed: ' . $e->getMessage()));
        }
    }

    /**
     * Opens a manual maintenance window to all visitors after its runtime row becomes inactive.
     *
     * @param Closure(ManualMaintenanceOutcome):void $reply One result after confirmation or refusal
     */
    protected function disableManualMaintenance(Closure $reply): void
    {
        if (!$this->admitManualMaintenanceRequest($reply)) {
            return;
        }

        $this->armManualMaintenance(self::MANUAL_DISABLE, $reply);

        try {
            $this->requestProtectedModeDisable();
        } catch (Throwable $e) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::refused('disable request failed: ' . $e->getMessage()));
        }
    }

    /**
     * Mints one manual maintenance pass; the clear value leaves only after its hash lands.
     *
     * @param Closure(ManualMaintenanceOutcome):void $reply One result after confirmation or refusal
     */
    protected function mintManualMaintenancePass(Closure $reply): void
    {
        if (!$this->admitManualMaintenanceRequest($reply)) {
            return;
        }

        try {
            $pass = $this->createProtectedModePass();
        } catch (RandomException $e) {
            $reply(ManualMaintenanceOutcome::refused('the secure random source refused: ' . $e->getMessage()));

            return;
        }

        $this->armManualMaintenance(self::MANUAL_PASS, $reply);
        $this->manualMaintenancePass = $pass;
        $this->manualMaintenancePassHash = $this->hashProtectedModePass($pass);

        try {
            $this->requestProtectedModePass($this->manualMaintenancePassHash);
        } catch (Throwable $e) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::refused('pass request failed: ' . $e->getMessage()));
        }
    }

    /**
     * Finishes any protected-mode drive in flight, and keeps the open people surfaces in step with
     * the standing of the people they show (HIL-945).
     *
     * @throws HilosException Whatever the concrete agent's tick raises
     * @throws LogicException When a project runtime item factory rejects a row
     */
    public function onTick(): void
    {
        parent::onTick();

        $this->tickProtectedModeTestDriver();
        $this->tickProtectedModeOperator();
        $this->tickManualMaintenance();
        AccountStandingAudience::onAgentTick($this);
    }

    /**
     * Routes the ready relay to the one pending enable request.
     */
    public function onProtectedModeReady(): void
    {
        if ($this->manualMaintenanceAction === self::MANUAL_ENABLE) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::succeeded(StateProtectedModeRuntime::PHASE_VERIFYING));

            return;
        }

        $this->onProtectedModeTestReady();
    }

    /**
     * Routes a core refusal to the one pending enable request.
     *
     * @param string $reason Reason from the protected-mode daemon
     */
    public function onProtectedModeRefused(string $reason): void
    {
        if ($this->manualMaintenanceAction === self::MANUAL_ENABLE) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::refused($reason));

            return;
        }

        $this->onProtectedModeTestRefused($reason);
    }

    /**
     * Lets go of a closed connection's place among the people surfaces it had open (HIL-945).
     *
     * @param WebSocketCloseSignalDTO $data Closed connection
     * @param string $source Signal origin
     * @param string $name Connection-close signal name
     * @throws HilosException When the parent close handler fails
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        parent::onSignalConnectionClose($data, $source, $name);
        AccountStandingAudience::removeSubscriber($data->acceptKey);
    }

    /**
     * Forgets the people surfaces before another instance uses this worker.
     *
     * @throws HilosException When parent cleanup fails
     */
    public function onStop(): void
    {
        $this->clearManualMaintenance();
        parent::onStop();
        AccountStandingAudience::reset();
    }

    /**
     * Routes the command-channel commands declared in {@see AGENT_COMMANDS}.
     *
     * Every path answers exactly once: a CLI parked on the command socket learns the outcome
     * instead of timing out.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused; the routing is on $data->command)
     * @throws InvalidArgumentException When the command reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        if (
            ($this->isProtectedModeTestCommand($data->command) || $this->isProtectedModeOperatorCommand($data->command))
            && $this->protectedModeRequestInFlight()
        ) {
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'another protected-mode request is still in flight',
            ));

            return;
        }

        if ($this->isProtectedModeTestCommand($data->command)) {
            $this->handleProtectedModeTestCommand($data);

            return;
        }

        if ($this->isProtectedModeOperatorCommand($data->command)) {
            $this->handleProtectedModeOperatorCommand($data);

            return;
        }

        // The echo has no handler of its own on purpose: what it proves is that a request reached
        // an agent and a reply came back, so anything between the two would be the probe testing
        // itself instead of the channel.
        if ($data->command === CliCommands::COMMAND_TEST_ECHO) {
            $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, $data->payload));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
    }

    /**
     * Admits a manual disable or pass only for this index agent's direct window.
     *
     * @param Closure(ManualMaintenanceOutcome):void $reply Refusal receiver
     * @return bool Whether the request may be sent to the protected-mode core
     */
    private function admitManualMaintenanceRequest(Closure $reply): bool
    {
        if ($this->protectedModeRequestInFlight()) {
            $reply(ManualMaintenanceOutcome::refused('another protected-mode request is still in flight'));

            return false;
        }

        $freeze = $this->manualMaintenanceRow();
        if ($freeze === null) {
            $reply(ManualMaintenanceOutcome::refused('protected mode is not mounted on this node'));

            return false;
        }

        if ($freeze->phase !== StateProtectedModeRuntime::PHASE_VERIFYING) {
            $reply(ManualMaintenanceOutcome::refused("the mode is '{$freeze->phase}', not verifying"));

            return false;
        }

        if (
            $freeze->operation !== StateProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE
            || $freeze->entryMode !== StateProtectedModeRuntime::ENTRY_MODE_VERIFICATION_WINDOW
        ) {
            $reply(ManualMaintenanceOutcome::refused('the current window is not manual maintenance'));

            return false;
        }

        $index = $this->getIndex();
        if (
            $freeze->initiatorAgentType !== $this->getType()
            || $freeze->initiatorAgentIndex !== ($index === null ? null : (int)$index)
        ) {
            $reply(ManualMaintenanceOutcome::refused('manual maintenance was initiated by another agent'));

            return false;
        }

        return true;
    }

    /**
     * @return bool Whether any protected-mode path on this agent has an unresolved request
     */
    private function protectedModeRequestInFlight(): bool
    {
        return $this->manualMaintenanceReply !== null
            || $this->protectedModeTestCorrelationId !== null
            || $this->protectedModeOperatorCorrelationId !== null;
    }

    /**
     * @param string $action Manual request kind
     * @param Closure(ManualMaintenanceOutcome):void $reply Result receiver
     */
    private function armManualMaintenance(string $action, Closure $reply): void
    {
        $this->manualMaintenanceReply = $reply;
        $this->manualMaintenanceAction = $action;
        $this->manualMaintenanceSince = microtime(true);
    }

    /**
     * Completes a pending manual request after dropping its callback and clear pass.
     *
     * @param ManualMaintenanceOutcome $outcome Result delivered exactly once
     */
    private function finishManualMaintenance(ManualMaintenanceOutcome $outcome): void
    {
        $reply = $this->manualMaintenanceReply;
        $this->clearManualMaintenance();
        $reply?->__invoke($outcome);
    }

    /**
     * Clears the request and its only in-process clear pass copy.
     */
    private function clearManualMaintenance(): void
    {
        $this->manualMaintenanceReply = null;
        $this->manualMaintenanceAction = null;
        $this->manualMaintenanceSince = 0.0;
        $this->manualMaintenancePassHash = '';
        $this->manualMaintenancePass = null;
    }

    /**
     * Watches only the local runtime row and the clock while a manual request is pending.
     */
    private function tickManualMaintenance(): void
    {
        if ($this->manualMaintenanceReply === null) {
            return;
        }

        $freeze = $this->manualMaintenanceRow();
        $phase = $freeze?->phase;

        if (
            $this->manualMaintenanceAction === self::MANUAL_DISABLE
            && $freeze !== null
            && $phase === StateProtectedModeRuntime::PHASE_INACTIVE
        ) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::succeeded($phase));

            return;
        }

        if (
            $this->manualMaintenanceAction === self::MANUAL_PASS
            && $freeze !== null
            && in_array($this->manualMaintenancePassHash, $freeze->passHashes, true)
        ) {
            $this->finishManualMaintenance(ManualMaintenanceOutcome::succeeded($phase, $this->manualMaintenancePass));

            return;
        }

        if ((microtime(true) - $this->manualMaintenanceSince) < CommandChannelWindows::AGENT_WAIT_SECONDS) {
            return;
        }

        $waited = CommandChannelWindows::AGENT_WAIT_SECONDS;
        $reason = $this->manualMaintenanceAction === self::MANUAL_PASS
            ? "protected mode did not record the pass within {$waited}s; if it lands late, close and reopen the window to void it"
            : "protected mode did not complete {$this->manualMaintenanceAction} within {$waited}s";
        $this->finishManualMaintenance(ManualMaintenanceOutcome::unconfirmed($reason, $phase));
    }

    /**
     * @return ?ProtectedModeRuntime This worker's protected-mode row, or null when unmounted
     */
    private function manualMaintenanceRow(): ?ProtectedModeRuntime
    {
        return Hilos::$rt?->hilosProtectedModeRuntime;
    }
}

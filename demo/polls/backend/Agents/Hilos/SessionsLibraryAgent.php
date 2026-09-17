<?php

declare(strict_types=1);

namespace Demo\Polls\Agents\Hilos;

use Demo\Polls\Agents\PollsAgent;
use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Hilos;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

/**
 * The polls demo's sessions library - and the two seams a project can be asked to answer.
 *
 * Everything a session is lives in {@see AbstractSessionsLibraryAgent} (HIL-710). What this
 * demo has to say for itself is the end of the two operator paths to an administrator:
 * {@see CliCommands::ADMIN_CREATE}, which has to be able to mint the first account because
 * this demo has no login of its own, and {@see CliCommands::ADMIN_GRANT}, which names a user
 * that already exists. The framework resolves the session, binds it and tells the tabs; the
 * rows are this demo's.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by this demo's own topology,
 * which is also what makes the handshake arrive here rather than in {@see PollsAgent}.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
    /**
     * The users table this library mints into, and the holds a registration parks on.
     *
     * The users table is claimed from this library's OWN process. The truth-source registry is
     * per process, so the claim the project agent makes covers that agent's worker and nothing
     * else: without this the minted administrator would be refused as a write with no truth
     * source behind it.
     *
     * The registration holds are the framework library's claim, made here because only a project
     * knows whether it has a sign-in surface at all. The table is mounted by
     * {@see AuthFeature::mount()} and written by the hold sweep the library arms behind the same
     * question, and a class constant has no way to ask it - so the demo that has the surface says
     * so, and one that has none claims a table it never writes.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        PollsDbContext::users => TruthSourceOperation::BY_KIND,
        // TODO(HIL-630): borrowed claim - the users library owns the reservation table. The hold
        // sweep is armed here because the expiry it announces rolls back a WAIT, which is the
        // sessions library's row; the sweep itself belongs with the table.
        HilosDbContext::registrationReservations => TruthSourceOperation::BY_KIND,
    ];

    /**
     * The two waits a sign-in parks a browser on, and the line that says how its code is
     * travelling: a registration in progress, a recovery, and the send behind them (HIL-826).
     *
     * Declared here rather than by {@see AbstractSessionsLibraryAgent} because the collections
     * exist only where a sign-in surface does: {@see AuthFeature::mount()} mounts them and nothing
     * else does, so a library that claimed them in a project without one would read a collection
     * that is not there on every tick. The users library stands beside the two waits as a declared
     * add/remove co-owner (HIL-685) rather than as a second full owner; the progress line has no
     * second writer at all - the transports carrying the code report to this library by frame.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [
        StateRegistrationWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateRecoveryWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosCodeSendAttempt::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    /**
     * Makes one user an administrator, minting the row when the session carries none.
     *
     * The session bind around this is the framework's; all that happens here is the user
     * table.
     *
     * @param ?int $userId User the session carries, or null when it carries none
     * @return int Id of the user that is now an administrator
     * @throws ItemNotFoundForUpdateException When the id names no user row
     * @throws HilosException On database failure while minting or flagging
     */
    protected function ensureAdminUser(?int $userId): int
    {
        if ($userId === null) {
            return (int)Hilos::$db->users->actions->registerAdmin()->id;
        }

        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        $user->actions->setAdmin(true);

        return $userId;
    }

    /**
     * Writes the admin flag of one user - and nothing else.
     *
     * The framework half of the grant ends at this seam: the command is validated and
     * answered there, and what a user row is lives here. Telling the person's open tabs is
     * the library's too since HIL-729 - it states the session and this demo's project agent
     * says it out loud, which is the one path every other identity change already travels.
     *
     * It writes under the same claim {@see self::OWNS_DB} makes for the minting seam above:
     * the truth-source registry is per process, and this library has its own.
     *
     * @param int $userId Target user id, already validated as positive
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When no user carries that id
     * @throws HilosException On database failure while writing the flag
     */
    protected function applyAdminGrant(int $userId, bool $admin): void
    {
        $user = Hilos::$db->users[$userId] ?? null;
        if ($user === null) {
            throw new ItemNotFoundForUpdateException("No such user: {$userId}");
        }

        $user->actions->setAdmin($admin);
    }
}

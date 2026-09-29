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
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Users\AccountErasure;

/**
 * The polls demo's sessions library - its own way in, and its own rows in an erasure.
 *
 * Everything a session is lives in {@see AbstractSessionsLibraryAgent} (HIL-710), and so do the
 * operations over the person since HIL-1197: {@see CliCommands::ADMIN_CREATE} and the grant
 * pair write `hilos_user` in the framework, the block and the takeover check alongside them.
 * What this demo has to say for itself is the claims over its own sign-in - the waits and the
 * registration holds - and the erasure of an account whose deletion fell due (HIL-302): the
 * framework erases the ways in and signs the person out, and the rename audit and the user row
 * are this demo's to delete.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by this demo's own topology,
 * which is also what makes the handshake arrive here rather than in {@see PollsAgent}.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
    /** Row family this demo reports in an account erasure: the person's rename journal rows (HIL-302). */
    private const string ROWS_ERASED_RENAMES = 'renames';

    /**
     * The person rows an erasure deletes, and the holds a registration parks on.
     *
     * Minting an administrator and writing the admin and block flags are claimed by the
     * framework's base (HIL-1197); what this demo adds on the person table is removing the row
     * when an account is erased, from this library's OWN process - the truth-source registry is
     * per process, and the claim the users library makes covers its own worker and nothing else.
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
        // TODO(HIL-1200): the framework deletes the person row after the project's own rows, and this claim goes.
        PollsDbContext::users => [TruthSourceOperation::Remove],
        // TODO(HIL-1200): the framework erases the rename journal and this claim goes. Borrowed until
        // then - the users library writes the journal; an erasure deletes the person's rows before
        // their user row (HIL-302).
        HilosDbContext::userRenames => [TruthSourceOperation::Remove],
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
     * that is not there on every tick. None of them has a second writer: the users library asks
     * for every park by frame (HIL-1044, it was an add/remove co-owner of the waits since HIL-685),
     * and the transports carrying the code report to this library by frame.
     * Neither have the provider sign-ins tabs are waiting on, which stand here for the same
     * reason (HIL-1044): the agents carrying the exchange report every ending by frame.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [
        StateRegistrationWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateRecoveryWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosCodeSendAttempt::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosOAuthTrip::RT_COLLECTION => TruthSourceOperation::BY_KIND,
    ];

    /**
     * Deletes everything this demo keeps of a person whose account is being erased (HIL-302).
     *
     * The person's rows of the framework's rename journal first, because they restrict the delete
     * of the user row, then the row itself; where the person only renamed somebody else, the
     * database clears the author when the row goes. Nothing here points at a file. Runs inside the framework's erasure
     * transaction, so a failure rolls back the ways in that went before it.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure The journal rows deleted, and no files
     * @throws ItemNotFoundForUpdateException When the user row cannot be deleted (id is null)
     * @throws HilosException On database or truth-source failure while deleting the rows
     */
    protected function applyAccountErasure(int $userId): AccountErasure
    {
        $renames = Hilos::$db->userRenames->actions->deleteByUser($userId);
        Hilos::$db->users[$userId]?->actions->delete();

        return new AccountErasure([self::ROWS_ERASED_RENAMES => $renames], []);
    }
}

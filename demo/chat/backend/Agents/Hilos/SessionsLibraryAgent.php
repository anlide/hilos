<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Hilos;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Users\AccountErasure;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

/**
 * The chat demo's sessions library - five seams wide, and every one of them a chat column
 * the framework cannot see (HIL-710, HIL-729, HIL-302).
 *
 * Everything a session is went into {@see AbstractSessionsLibraryAgent} whole: resolving a
 * handshake cookie, rotating a token, raising a session to a person and reverting it. Six
 * seams a project can be asked to answer stand on it, and this demo answers five.
 *
 * {@see CliCommands::ADMIN_CREATE} is the one it does not - the mount stands on the abstract
 * class, so every subclass inherits it - and an operator who types it at this installation
 * gets the refusing default. That refusal is the point rather than a gap: it is the honest
 * answer to a command aimed at a demo that mints its administrators through its own sign-in.
 *
 * {@see CliCommands::ADMIN_GRANT} is one this demo does answer, because it names a user that
 * already exists and chat keeps its own user rows. All the seam does is write the flag:
 * telling the person's open tabs is the library's, and used to be three project copies of one
 * broadcast (HIL-729).
 *
 * The impersonation pair is where a project's answer is smallest: the library writes the
 * takeover and asks only whether it is allowed
 * ({@see AbstractSessionsLibraryAgent::assertImpersonationAllowed()}). Before HIL-729 the
 * whole operation lived in {@see ChatAgent} for the sake of that one question.
 *
 * The merge pair is where it is largest, and it is still not the operation: the framework
 * moves the ways in and signs the loser out, and asks this demo the two things only it knows
 * - whether these two accounts may be merged at all, and what a chat keeps for a person.
 *
 * The erasure is the merge's opposite and asks the same second question the other way round
 * (HIL-302): when a person's account deletion falls due, the framework erases the ways in and
 * signs them out, and this demo deletes what a chat keeps for them - their messages with the
 * attachments, the events about them, their row - and names the files to remove.
 *
 * What stayed in {@see ChatAgent} is the other half of the seam: who is on the wire, what
 * that person is called, and the tab that has to be told. The library says what a session
 * became and what a merge did; the chat agent says both out loud.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by the chat's own topology,
 * which is also what makes the handshake arrive here rather than in the chat agent.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
    /**
     * The chat tables this library writes on its way through a person, and the holds a
     * registration parks on.
     *
     * The chat pair is borrowed and narrow, and the reason the rights have to be said out loud at
     * all is HIL-716: they used to be asked only of a collection loaded whole, so a lazily loaded
     * table was written by anybody in silence. They are asked of every table now, and the registry
     * is per process - so this library holds its own grant rather than leaning on the one the
     * owner registered in some other worker.
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
        // TODO(HIL-630): borrowed claim - the users library owns the account set. What this
        // library does to a chat user is set the admin flag, tombstone the loser of a merge and
        // delete the row of an erased account (HIL-302).
        ChatDbContext::users => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        // TODO(HIL-626): borrowed claim - the chat agent owns the message rows. A merge
        // re-points the loser's messages onto the survivor; an erasure deletes the person's.
        ChatDbContext::eventMessages => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
        // TODO(HIL-626): borrowed claim - the chat agent owns the attachment rows; an erasure
        // deletes those of the person's messages (HIL-302).
        ChatDbContext::eventAttachments => [TruthSourceOperation::Remove],
        // TODO(HIL-626): borrowed claim - the chat agent owns the events; an erasure deletes
        // the person's messages and the events about them (HIL-302).
        ChatDbContext::events => [TruthSourceOperation::Remove],
        // TODO(HIL-630): borrowed claim - the users library writes the registration events; an
        // erasure deletes the person's (HIL-302).
        ChatDbContext::eventUserRegistrations => [TruthSourceOperation::Remove],
        // TODO(HIL-630): borrowed claim - the users library writes the rename events; an erasure
        // deletes the person's and takes them off the renames of others they made (HIL-302).
        ChatDbContext::eventUserRenames => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
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
     * Writes the admin flag of one chat user - and nothing else.
     *
     * The claim behind the write is the one {@see self::onStart()} makes.
     *
     * The announcement that used to follow the write here is the framework's now: the library
     * states the session and {@see ChatAgent} says it out loud, which is the one path every
     * other identity change already travels.
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

    /**
     * Decides whether one chat user may take another over.
     *
     * Both halves are refused by throwing, in the order the guards used to run in
     * {@see ChatAgent} so the refusals a caller can see are unchanged: the asker must carry
     * the chat `admin` flag, and only then is the target looked up at all. An unprivileged
     * caller therefore never learns from this whether the id it named exists.
     *
     * Nothing here says the target may not be an administrator too. Admin-on-admin takeover
     * was allowed before the move and stays allowed; what the library refuses on its own is
     * the degenerate case of a session naming its own user.
     *
     * @param int $adminUserId User the acting session currently carries
     * @param int $targetUserId User that session asks to act as
     * @throws ValidationException When the asker is not an administrator or the target is unknown
     */
    protected function assertImpersonationAllowed(int $adminUserId, int $targetUserId): void
    {
        $admin = Hilos::$db->users[$adminUserId] ?? null;
        if ($admin === null || !$admin->admin) {
            throw new ValidationException('Session is not an admin session');
        }

        if ((Hilos::$db->users[$targetUserId] ?? null) === null) {
            throw new ValidationException("No such user: {$targetUserId}");
        }
    }

    /**
     * Vouches for both accounts of a merge - chat's first half of the pair.
     *
     * Both questions are chat's because the user rows are: whether an id names anybody at
     * all, and whether that account has already been folded into a third. The order and the
     * wording are the ones the guards ran in while the merge lived in {@see ChatAgent}, so no
     * refusal an operator or an admin can see has changed.
     *
     * @param int $survivorUserId Survivor user id that would absorb the loser
     * @param int $loserUserId Loser user id that would be folded in
     * @throws ValidationException When either id names nobody, or either account is already merged
     */
    protected function assertMergeable(int $survivorUserId, int $loserUserId): void
    {
        $survivor = Hilos::$db->users[$survivorUserId] ?? null;
        if ($survivor === null) {
            throw new ValidationException("No such user: {$survivorUserId}");
        }
        if ($survivor->mergedInto !== null) {
            throw new ValidationException("Survivor {$survivorUserId} is itself a merged account");
        }

        $loser = Hilos::$db->users[$loserUserId] ?? null;
        if ($loser === null) {
            throw new ValidationException("No such user: {$loserUserId}");
        }
        if ($loser->mergedInto !== null) {
            throw new ValidationException("Loser {$loserUserId} is already merged");
        }
    }

    /**
     * Moves what chat keeps for a person onto the survivor, and tombstones the loser.
     *
     * Everything a chat holds for somebody is their messages, so the tally goes back under
     * one family name. The tombstone is here rather than in the framework because
     * `mergedInto` is a chat column: the framework knows an account was folded away, this
     * demo knows where it says so.
     *
     * Runs inside the framework's merge transaction, so a failure of either write rolls back
     * the identity re-point that came before it.
     *
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @return array<string, int> The messages that moved, under chat's own family name
     * @throws ItemNotFoundForUpdateException When the loser row went missing between the guard and the write
     * @throws HilosException On database or truth-source failure while moving the rows
     */
    protected function applyAccountMerge(int $survivorUserId, int $loserUserId): array
    {
        $messagesMoved = Hilos::$db->eventMessages->actions->rePointAuthor($loserUserId, $survivorUserId);

        $loser = Hilos::$db->users[$loserUserId]
            ?? throw new ItemNotFoundForUpdateException("No such user: {$loserUserId}");
        $loser->actions->tombstone($survivorUserId);

        return [ChatCommandConstants::ROWS_MOVED_MESSAGES => $messagesMoved];
    }

    /**
     * Deletes everything a chat keeps of a person whose account is being erased (HIL-302).
     *
     * Children before their parents, because the registration and rename events restrict the
     * delete of the user row: the attachments of the person's messages, the messages, the
     * registration and rename events about the person, then those events themselves. Where the
     * person only renamed somebody else, the rename stays and loses its actor. The person's row
     * goes last. The attachment files are named for the framework to remove after the commit.
     *
     * Runs inside the framework's erasure transaction, so a failure of any write rolls back
     * every one before it and the ways in that went first.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure Rows deleted under chat's own family names, and the attachment files
     * @throws ItemNotFoundForUpdateException When the user row cannot be deleted (id is null)
     * @throws HilosException On database or truth-source failure while deleting the rows
     */
    protected function applyAccountErasure(int $userId): AccountErasure
    {
        $messageIds = Hilos::$db->eventMessages->eventIdsByAuthor($userId);
        $files = Hilos::$db->eventAttachments->actions->deleteForMessages($messageIds);
        $messages = Hilos::$db->eventMessages->actions->deleteByAuthor($userId);
        $registrationIds = Hilos::$db->eventUserRegistrations->actions->deleteByTarget($userId);
        $renameIds = Hilos::$db->eventUserRenames->actions->deleteByTarget($userId);
        Hilos::$db->eventUserRenames->actions->clearActor($userId);
        $events = Hilos::$db->events->actions->deleteByIds([...$messageIds, ...$registrationIds, ...$renameIds]);
        Hilos::$db->users[$userId]?->actions->delete();

        return new AccountErasure(
            [
                ChatCommandConstants::ROWS_ERASED_MESSAGES => $messages,
                ChatCommandConstants::ROWS_ERASED_ATTACHMENTS => count($files),
                ChatCommandConstants::ROWS_ERASED_EVENTS => $events,
            ],
            $files,
        );
    }
}

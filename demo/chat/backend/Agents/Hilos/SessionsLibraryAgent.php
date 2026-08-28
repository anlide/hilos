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
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Environment\Exception\EnvException;
use Hilos\HilosException;

/**
 * The chat demo's sessions library - four seams wide, and every one of them a chat column
 * the framework cannot see (HIL-710, HIL-729).
 *
 * Everything a session is went into {@see AbstractSessionsLibraryAgent} whole: resolving a
 * handshake cookie, rotating a token, raising a session to a person and reverting it. Five
 * seams a project can be asked to answer stand on it, and this demo answers four.
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
     * @var list<string> What this demo's sign-in reaches for beyond the sessions it owns: the
     *     person behind the session, and the message row a parked sign-in surface belongs to.
     */
    public const array READS_DB = [
        ChatDbContext::users,
        ChatDbContext::eventMessages,
    ];

    /**
     * Claims the two chat tables this library writes on its way through a person.
     *
     * Both are borrowed and both are narrow, and the reason they have to be said out loud at
     * all is HIL-716: the right used to be asked only of a collection loaded whole, so a
     * lazily loaded table was written by anybody in silence. It is asked of every table now,
     * and the registry is per process - so this library holds its own grant rather than
     * leaning on the one the owner registered in some other worker. The two demos beside this
     * one already claimed their user table here for the same reason.
     *
     * @throws EnvException When the sweep schedule key the library reads is missing or malformed
     */
    public function onStart(): void
    {
        parent::onStart();

        // TODO(HIL-630): borrowed claim - the users library owns the account set. What this
        // library does to a chat user is set the admin flag and tombstone the loser of a
        // merge, both of them edits of a row that already exists.
        $this->registerDbTruthSource(
            ChatDbContext::users,
            operations: [TruthSourceOperation::Update],
        );
        // TODO(HIL-626): borrowed claim - the chat agent owns the message rows. A merge
        // re-points the loser's messages onto the survivor, which edits them and nothing more.
        $this->registerDbTruthSource(
            ChatDbContext::eventMessages,
            operations: [TruthSourceOperation::Update],
        );
    }

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
}

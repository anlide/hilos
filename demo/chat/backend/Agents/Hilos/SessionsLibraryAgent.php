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
 * The chat demo's sessions library - the merge and the erasure of what a chat keeps for a
 * person (HIL-710, HIL-729, HIL-302, HIL-1199).
 *
 * Everything a session is went into {@see AbstractSessionsLibraryAgent} whole: resolving a
 * handshake cookie, rotating a token, raising a session to a person and reverting it. So did
 * the operations over the person (HIL-1197): {@see CliCommands::ADMIN_CREATE}, the grant pair,
 * the block and the takeover check write and read `hilos_user` in the framework, the same in
 * this demo as in the other two. Before that this demo refused admin:create, and it was the one
 * way out of an installation whose every sign-in method is switched off. So did whether two
 * accounts may be merged, the loser's tombstone and every refusal to a merged account
 * (HIL-1199): they live in the framework's merge table, not in a chat column.
 *
 * The merge is where this demo's answer is largest, and it is still not the operation: the
 * framework checks both accounts, moves the ways in, tombstones the loser and signs it out, and
 * asks this demo the one thing only it knows - what a chat keeps for a person.
 *
 * The erasure is the merge's opposite and asks the same question the other way round
 * (HIL-302): when a person's account deletion falls due, the framework erases the ways in and
 * signs them out, and this demo deletes what a chat keeps for them - their messages with the
 * attachments and the events about them, and names the files to remove. The framework
 * removes the rename journal and person row after this hook (HIL-1200).
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
     * Moves what chat keeps for a person onto the survivor.
     *
     * Everything a chat holds for somebody is their messages, so the tally goes back under
     * one family name. The tombstone is the framework's, written after this returns.
     *
     * Runs inside the framework's merge transaction, so a failure rolls back the identity
     * re-point that came before it.
     *
     * @param int $survivorUserId Survivor user id that absorbs the loser
     * @param int $loserUserId Loser user id folded into the survivor
     * @return array<string, int> The messages that moved, under chat's own family name
     * @throws HilosException On database or truth-source failure while moving the rows
     */
    protected function applyAccountMerge(int $survivorUserId, int $loserUserId): array
    {
        $messagesMoved = Hilos::$db->eventMessages->actions->rePointAuthor($loserUserId, $survivorUserId);

        return [ChatCommandConstants::ROWS_MOVED_MESSAGES => $messagesMoved];
    }

    /**
     * Deletes everything a chat keeps of a person whose account is being erased (HIL-302).
     *
     * Children before their parents: attachments of the person's messages, the messages,
     * registration events and their rename feed events. The rename events are read from the
     * journal while it still exists. Where the person only renamed somebody else, that rename
     * and its feed line stay, and the database clears their author (HIL-1195). The framework
     * removes the person's journal and row after this hook, including for each folded account
     * in the erasure circle (HIL-1200). Attachment files are removed after the commit.
     *
     * Runs inside the framework's erasure transaction, so a failure of any write rolls back
     * every one before it and the ways in that went first.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure Rows deleted under chat's own family names, and the attachment files
     * @throws HilosException On database or truth-source failure while deleting the rows
     */
    protected function applyAccountErasure(int $userId): AccountErasure
    {
        $messageIds = Hilos::$db->eventMessages->eventIdsByAuthor($userId);
        $files = Hilos::$db->eventAttachments->actions->deleteForMessages($messageIds);
        $messages = Hilos::$db->eventMessages->actions->deleteByAuthor($userId);
        $registrationIds = Hilos::$db->eventUserRegistrations->actions->deleteByTarget($userId);
        $renameEventIds = Hilos::$db->userRenames->eventIdsByUser($userId);
        $events = Hilos::$db->events->actions->deleteByIds([...$messageIds, ...$registrationIds, ...$renameEventIds]);

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

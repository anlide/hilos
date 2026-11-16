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
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;

/**
 * The chat demo's sessions library - the sessions and merge of what a chat keeps for a
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
 * Project erasure belongs to ChatAgent, reached through its account-data owner directory.
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
     * The chat tables this library writes on its way through a person.
     *
     * The chat pair is borrowed and narrow, and the reason the rights have to be said out loud at
     * all is HIL-716: they used to be asked only of a collection loaded whole, so a lazily loaded
     * table was written by anybody in silence. They are asked of every table now, and the registry
     * is per process - so this library holds its own grant rather than leaning on the one the
     * owner registered in some other worker.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_DB = [
        // The existing merge moves messages until its owner protocol is activated.
        ChatDbContext::eventMessages => [TruthSourceOperation::Update],
    ];

    /**
     * Registration holds read at handshake and when finding sessions waiting on an address.
     *
     * Only the users library writes these holds (HIL-1411). The read is declared in the project
     * because only {@see AuthFeature::mount()} mounts the registration reservation table.
     *
     * @var list<string>
     */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::registrationReservations,
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
     * Nor have the profile windows half-way through (HIL-1182): the users library reports every step by frame.
     *
     * @var array<string, list<TruthSourceOperation>>
     */
    public const array OWNS_RT = [
        StateRegistrationWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateRecoveryWaiter::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosCodeSendAttempt::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosOAuthTrip::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosProfileFlow::RT_COLLECTION => TruthSourceOperation::BY_KIND,
        StateHilosProfileNameChange::RT_COLLECTION => TruthSourceOperation::BY_KIND,
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

}

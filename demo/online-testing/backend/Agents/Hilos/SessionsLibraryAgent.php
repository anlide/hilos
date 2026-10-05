<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Agents\Hilos;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Users\AccountErasure;

/**
 * The online-testing demo's sessions library - its own way in and registration holds.
 *
 * Everything a session is lives in {@see AbstractSessionsLibraryAgent} (HIL-710), and so do the
 * operations over the person since HIL-1197: {@see CliCommands::ADMIN_CREATE} and the grant
 * pair write `hilos_user` in the framework, the block and the takeover check alongside them.
 * What this demo has to say for itself is the claims over its own sign-in - the waits and the
 * registration holds. The framework erases the whole person, including the rename journal
 * and person row (HIL-1200); this demo has no additional rows to erase.
 *
 * Registered under {@see HilosAgentType::HILOS_SESSIONS_LIBRARY} by this demo's own topology,
 * which is also what makes the handshake arrive here rather than in {@see OnlineTestingAgent}.
 */
final class SessionsLibraryAgent extends AbstractSessionsLibraryAgent
{
    /**
     * The registration holds this library manages.
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
        // TODO(HIL-1411): borrowed claim - the users library owns the reservation table. The hold
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
    ];

    /**
     * Answers the framework's erasure hook for one account (HIL-1200).
     *
     * This demo has no project rows of the person. The framework removes their rename journal
     * and person row after this hook. The override answers because the default hook refuses.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure No project rows or files
     */
    protected function applyAccountErasure(int $userId): AccountErasure
    {
        return new AccountErasure([], []);
    }
}

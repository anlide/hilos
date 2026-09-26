<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;

/**
 * AbstractHilosProfileSecurityPage - the profile's security page: the second factor (HIL-494)
 * and the account deletion's danger zone (HIL-302).
 *
 * The framework owns the page's identity - key, route `/profile/security`, subscription signal
 * - so every project that signs people in exposes the same section; the concrete subclass
 * binds the agent that serves it. Like the profile it extends AbstractPage directly and is
 * signed-in only, for the reason {@see AbstractHilosProfilePage} gives.
 *
 * It is a READING surface. The subscription answers with the whole section of the person
 * behind the connection ({@see SecondFactorStateProjector}); every submit of the section - the
 * app connected or removed, the codes shown or renewed, the wait chosen, the removal asked for
 * or canceled - is a command of the users library, which owns the tables. After the answer the
 * connection joins the person's group ({@see SecondFactorGroup}), so a change made anywhere
 * reaches every tab showing the section.
 *
 * The danger zone rides along the same way: the person's deletion state under its own
 * section ({@see AccountDeletionStateProjector}), and its own group
 * ({@see AccountDeletionGroup}) for every start and cancel. A project whose profile has more
 * than one page draws the zone here, at the bottom of the security page.
 */
abstract class AbstractHilosProfileSecurityPage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE_SECURITY;

    public const PageReach REACH = PageReach::ROUTE;

    /** Signed-in-only surface; see {@see AbstractHilosProfilePage} for why this must stay explicit. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    /** Page-data section slot carrying the second-factor section. */
    public const string SECOND_FACTOR_SECTION = 'secondFactor';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE_SECURITY,
    ];

    /**
     * @var list<string> The four tables the section is read from, and the deletion requests the
     *     danger zone is; all of them are the users library's, written by its commands and by
     *     its removal sweep.
     */
    public const array READS_DB = [
        HilosDbContext::secondFactors,
        HilosDbContext::secondFactorBackupCodes,
        HilosDbContext::secondFactorResets,
        HilosDbContext::secondFactorSettings,
        HilosDbContext::accountDeletions,
    ];

    /**
     * Answers the subscription with the section of the person behind the connection.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; the page has none)
     * @return ?PagePayload The section and the deletion state, or null outside a signed-in session
     * @throws HilosException When a lookup or a setting read fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            self::SECOND_FACTOR_SECTION => SecondFactorStateProjector::stateFor($userId)->toArray(),
            AccountDeletionStateProjector::SECTION => AccountDeletionStateProjector::stateFor($userId)->toArray(),
        ]);
    }

    /**
     * Puts the connection on the person's two groups once the subscription is answered.
     *
     * The same two writes a group's own join makes: the membership in this worker's mirror, and
     * the word to the master, which keeps the fan-out list every process sends through.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused)
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return;
        }

        $group = SecondFactorGroup::forUser($userId);
        Hilos::$sr?->subscribeToGroup($group, new WebSocketGroupSubscribeSignalDTO(
            acceptKey: $acceptKey,
            group: $group,
            params: [],
        ));
        Hilos::$sr?->queueSignal(
            signalSource: $this->agent->getAgentSignalSource(),
            signalType: new SignalType(SignalTypeConstants::GROUP_JOIN),
            signalName: new SignalName(SignalTypeConstants::GROUP_JOIN),
            signalData: new GroupJoinSignalData($group, $acceptKey, []),
        );
        AccountDeletionGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
    }
}

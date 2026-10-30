<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\Profile\HilosProfileTimeZones;

/**
 * The framework current-user profile root, served by the project's agent.
 *
 * Supplies account deletion state and time zones beside the shared profile payload.
 * Account writes belong to the users library; profile sections own their own data.
 * The connection joins the person's deletion group after the answer.
 *
 * The common profile base keeps authenticated access, the photo flag and second-factor state
 * on every section; an anonymous session is denied with 401.
 */
abstract class AbstractHilosProfilePage extends AbstractHilosProfileSectionPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE,
    ];

    /** @var list<string> Account deletion reads beside the shared profile sources. */
    public const array READS_DB = [
        ...parent::READS_DB,
        HilosDbContext::accountDeletions,
    ];

    /**
     * Answers the subscription with the sections of the person behind the connection.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; the profile has none)
     * @return ?PagePayload Deletion state and time zones, or null outside a signed-in session
     * @throws HilosException When the account deletion lookup fails
     */
    protected function buildProfilePagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            AccountDeletionStateProjector::SECTION => AccountDeletionStateProjector::stateFor($userId)->toArray(),
        ], lists: [HilosProfileTimeZones::LIST => HilosProfileTimeZones::toArray()]);
    }

    /**
     * Joins the person's account deletion group after answering.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; the profile has none)
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    protected function onProfileSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return;
        }

        AccountDeletionGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
    }
}

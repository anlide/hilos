<?php

declare(strict_types=1);

namespace Hilos\Pages;

use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Notification\NotificationChannelPreferenceProjector;

/**
 * The framework current-user profile root, served by the project's agent.
 *
 * The framework owns the page key, route and subscription signal, and the answer (HIL-1169):
 * the person's sections the root's rows summarize - notifications, two-step verification,
 * agreements, the data copy - and the account deletion's danger zone at its bottom. The
 * project binds its agent and, when it has them, its own browser lists and data for the name
 * and the summaries only it knows. Each profile section has its own page (HIL-493); the
 * provider-link start belongs to AbstractHilosProfileSignInPage and account writes belong to
 * the users library.
 *
 * It is a READING surface. After the answer the connection joins the person's deletion,
 * agreements, second-factor and data-copy groups, so a change made in another tab reaches
 * this one's rows.
 *
 * AUTHENTICATED must stay explicit: this page extends AbstractPage directly, bypassing the
 * admin default of AbstractHilosPage, and denies an anonymous session with 401.
 */
abstract class AbstractHilosProfilePage extends AbstractPage
{
    public const string PAGE = HilosPageConstants::HILOS_PROFILE;

    public const PageReach REACH = PageReach::ROUTE;

    /** Signed-in-only surface; see the class doc for why this must stay explicit. */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_PROFILE,
    ];

    /**
     * @var list<string> What the answer's projectors read: the notification section and the two
     *     stores a channel resolves an address in, deletion requests, legal acceptances, the four
     *     second-factor tables and data-copy requests. A project whose binding reads more
     *     replaces the list with a wider one.
     */
    public const array READS_DB = [
        HilosDbContext::identities,
        HilosDbContext::notificationPreferences,
        HilosDbContext::pushSubscriptions,
        HilosDbContext::accountDeletions,
        HilosDbContext::legalAcceptances,
        HilosDbContext::secondFactors,
        HilosDbContext::secondFactorBackupCodes,
        HilosDbContext::secondFactorResets,
        HilosDbContext::secondFactorSettings,
        HilosDbContext::dataExports,
    ];

    /**
     * Answers the subscription with the sections of the person behind the connection.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; the profile has none)
     * @return ?PagePayload Data-copy, agreements, notification, deletion and second-factor state, or null outside a signed-in session
     * @throws HilosException When a preference, address, deletion, data-copy, acceptance or second-factor lookup fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return null;
        }

        return new PagePayload(data: [
            DataExportStateProjector::SECTION => DataExportStateProjector::nodeFor(Hilos::$db->dataExports->ofUser($userId)),
            LegalAgreementsProjector::SECTION => LegalAgreementsProjector::stateFor($userId, LegalStandingResolver::today())->toArray(),
            AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()
                ->sectionData($userId)
                ->toArray(),
            AccountDeletionStateProjector::SECTION => AccountDeletionStateProjector::stateFor($userId)->toArray(),
            AbstractHilosProfileSecurityPage::SECOND_FACTOR_SECTION => SecondFactorStateProjector::stateFor($userId)->toArray(),
        ]);
    }

    /**
     * Joins the person's account deletion, data-copy, second-factor and agreements groups after answering.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; the profile has none)
     * @throws InvalidArgumentException When the join announcement cannot be named
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser?->resolveActionUserId($acceptKey);
        if ($userId === null) {
            return;
        }

        AccountDeletionGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        LegalAgreementsGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        SecondFactorGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        DataExportGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
    }
}

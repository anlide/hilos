<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\UsersLibraryAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Hilos;
use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\HilosException;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Pages\AbstractHilosProfileSecurityPage;

/**
 * Chat demo implementation of the framework current-user profile page.
 *
 * The framework owns the page identity (key, route, subscription signal); this concrete binds
 * the chat agent and the self-connection browser data. The subscription carries notifications,
 * second-factor state, account deletion state, data-copy state and legal agreements beside the section summaries' browser lists.
 *
 * It is a READING surface (HIL-771). Every submit that writes a person lives where those tables
 * are owned: the rename on {@see UsersLibraryAgent}, the ways in and the email change on the
 * framework's users library (HIL-1137). Starting a provider link belongs to ProfileSignInPage.
 * This page is still served by the chat agent, whose browser data it reads.
 *
 * After answering, it joins the deletion, data-export, second-factor and legal-agreements groups so the person's state
 * remains live across tabs, as on the framework's security page.
 *
 * @property ChatAgent $agent
 */
final class ProfilePage extends AbstractHilosProfilePage
{
    /**
     * @var list<string> What is left to read once the writing submits have gone (HIL-771): the
     *     notification section the subscription carries, the two stores a channel resolves
     *     a person's address in, account deletion requests, data-copy requests, legal acceptances, and the second-factor section.
     */
    public const array READS_DB = [
        ChatDbContext::identities,
        ChatDbContext::notificationPreferences,
        ChatDbContext::pushSubscriptions,
        ChatDbContext::sessions,
        ChatDbContext::accountDeletions,
        ChatDbContext::legalAcceptances,
        ChatDbContext::secondFactors,
        ChatDbContext::secondFactorBackupCodes,
        ChatDbContext::secondFactorResets,
        ChatDbContext::secondFactorSettings,
        ChatDbContext::dataExports,
    ];

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    /**
     * Contributes the signed-in user's notification preferences as the profile's
     * page-data section, plus account deletion, data-export, second-factor and legal-agreements state.
     *
     * The profile is a self-only surface with no route params: the recipient is the
     * session user, read from the self-connection (never a client value), so an
     * anonymous or session-less subscribe contributes no section and leaves the
     * browser identities snapshot to run alone. The section is a computed projection
     * ({@see NotificationChannelPreferenceProjector::sectionData()}), not a browser
     * snapshot, so it rides the one-shot subscription payload rather than the
     * reactive browser data.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection (unused; this page reads its subscriber off the self-connection)
     * @param PageRouteParams $params Route params for the profile subscription (unused; profile has none)
     * @return ?PagePayload Notification, deletion, data-export, second-factor and legal-agreements state, or null outside a signed-in session
     * @throws HilosException When a preference, address, deletion, data-export or second-factor lookup fails
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        if (Hilos::$rt->selfConnection === null || Hilos::$rt->selfConnection->userId === null) {
            return null;
        }

        return new PagePayload(data: [
            DataExportStateProjector::SECTION => DataExportStateProjector::nodeFor(
                Hilos::$db->dataExports->ofUser(Hilos::$rt->selfConnection->userId),
            ),
            LegalAgreementsProjector::SECTION => LegalAgreementsProjector::stateFor(
                Hilos::$rt->selfConnection->userId, LegalStandingResolver::today(),
            )->toArray(),
            AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION => new NotificationChannelPreferenceProjector()
                ->sectionData(Hilos::$rt->selfConnection->userId)
                ->toArray(),
            AccountDeletionStateProjector::SECTION => AccountDeletionStateProjector::stateFor(
                Hilos::$rt->selfConnection->userId,
            )->toArray(),
            AbstractHilosProfileSecurityPage::SECOND_FACTOR_SECTION => SecondFactorStateProjector::stateFor(
                Hilos::$rt->selfConnection->userId,
            )->toArray(),
        ]);
    }

    /**
     * Joins the person's account deletion, data-export, second-factor and legal-agreements groups after answering.
     *
     * @param string $acceptKey WebSocket accept key of the subscribing connection
     * @param PageRouteParams $params Route params (unused; profile has none)
     * @throws HilosException When the join announcement cannot be named
     */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$rt->selfConnection?->userId;
        if ($userId === null) {
            return;
        }

        AccountDeletionGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        LegalAgreementsGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        SecondFactorGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
        DataExportGroup::join($acceptKey, $userId, $this->getAgentSignalSource());
    }
}

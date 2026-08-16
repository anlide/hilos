<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Database\Object\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Handles the chat user-detail subscription.
 *
 * Contributes the requested user's profile as a page-scope entity through
 * buildPagePayload(); the runtime presence rides the reactive browser snapshot
 * (the `userPresence` data source), so it stays out of the one-shot entity
 * payload.
 *
 * @property ChatAgent $agent
 */
final class UserPage extends AbstractPage
{
    public const string PAGE = PageConstants::USER;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    /** Page payload entity slot carrying the requested user's profile. */
    public const string ENTITY_SLOT = 'user';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_USER,
        BrowserConfigKey::PARAMS => [
            UserPageSubscribeParams::USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserGuardKey::KEY => ChatBrowserRef::USER_ID,
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];

    /**
     * Validates the route param DTO before emitting the browser snapshot.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params for the user detail subscription
     * @throws MissingPageRouteParamException When `id` is absent
     * @throws InvalidPageRouteParamException When `id` is non-numeric or `<= 0`
     * @throws PageSubscriptionException When the browser snapshot rejects the subscription
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        UserPageSubscribeParams::fromPageRouteParams($params);

        parent::onSubscribe($acceptKey, $params);
    }

    /**
     * Builds the user detail page payload: the requested user's profile as a
     * page-scope entity the frontend resolves by reference. The row is read
     * strictly, because the DB_EXISTS guard has already refused a subscription
     * naming a user that is not there: an absent row here means the guard did not
     * run, and a quiet empty page would hide that.
     *
     * @param PageRouteParams $params Route params for the user detail subscription
     * @return ?PagePayload User profile entity payload
     * @throws MissingPageRouteParamException When `id` is absent
     * @throws InvalidPageRouteParamException When `id` is non-numeric or `<= 0`
     * @throws PageInternalErrorException When the guarded user row is absent all the same
     */
    protected function buildPagePayload(PageRouteParams $params): ?PagePayload
    {
        $userId = UserPageSubscribeParams::fromPageRouteParams($params)->userId;
        $user = Hilos::$db->users[$userId]
            ?? throw new PageInternalErrorException("User #{$userId} passed the guard and is not in the database");

        return new PagePayload(entities: [
            self::ENTITY_SLOT => [
                User::id => $user->id,
                User::name => $user->name,
                User::lastActivity => $user->lastActivity,
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;

/**
 * Handles the chat user-detail browser subscription.
 *
 * @property ChatAgent $agent
 */
final class UserPage extends AbstractPage
{
    public const string PAGE = PageConstants::USER;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

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
}

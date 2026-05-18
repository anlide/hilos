<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageRouteParams;

/**
 * AdminPage - Admin page handler.
 *
 * Handles subscription, unsubscription, and actions for the admin page.
 *
 * @property ChatAgent $agent
 */
final class AdminPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
    ];

    /**
     * Sends an empty browser page payload for the admin landing page.
     *
     * @param string $acceptKey Accept key
     * @param PageRouteParams $params Route params from page subscription (unused for admin page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
            $acceptKey,
            new BrowserPageSignalData(),
        );
    }
}

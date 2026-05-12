<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
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

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
    ];

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param PageRouteParams $params Route params from page subscription (unused for admin page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_ADMIN,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
    }
}

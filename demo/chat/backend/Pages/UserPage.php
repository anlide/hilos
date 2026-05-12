<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Demo\Chat\Core\Router\DTO\ChatEventSignalDTO;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\EntitiesChangesDTO;
use Hilos\Core\Page\PageRouteParams;

/**
 * UserPage - User page handler.
 *
 * Handles subscription, unsubscription, and actions for the user page.
 *
 * @property ChatAgent $agent
 */
final class UserPage extends AbstractPage
{
    public const string PAGE = PageConstants::USER;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_USER,
        BrowserConfigKey::TABLES => [
            ChatBrowserTable::USER_DETAIL => [],
        ],
    ];

    /**
     * Handle page-specific subscription logic.
     *
     * @param string $acceptKey Accept key
     * @param PageRouteParams $params Route params from page subscription (e.g. ['id' => userId])
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_USER,
            $acceptKey,
            new ChatEventSignalDTO(new EntitiesChangesDTO()),
        );
    }
}

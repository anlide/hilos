<?php

declare(strict_types=1);

namespace Demo\Chat\Pages;

use Demo\Chat\Agents\ModeratorAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\PageConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageRouteParams;

/**
 * ModeratorPage - Moderator page handler.
 *
 * Handles subscription, unsubscription, and actions for the moderator page.
 *
 * @property ModeratorAgent $agent
 */
final class ModeratorPage extends AbstractPage
{
    public const string PAGE = PageConstants::MODERATOR;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => ChatSignalConstants::SUBSCRIPTION_PAGE_MODERATOR,
    ];

    /**
     * Sends an empty browser page payload for the moderator landing page.
     *
     * @param string $acceptKey Accept key
     * @param PageRouteParams $params Route params from page subscription (unused for moderator page)
     */
    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        $this->sendToUser(
            ChatSignalConstants::SUBSCRIPTION_PAGE_MODERATOR,
            $acceptKey,
            new BrowserPageSignalData(),
        );
    }
}

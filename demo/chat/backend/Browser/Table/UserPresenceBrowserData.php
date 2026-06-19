<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser data source for the chat user detail page: the user's reactive
 * runtime presence. The user profile is delivered separately as a page entity
 * by {@see \Demo\Chat\Pages\UserPage::buildPagePayload()}.
 */
final class UserPresenceBrowserData
{
    public const string DATA = ChatBrowserTable::USER_PRESENCE;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            UserPageSubscribeParams::USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::RT_CONNECTIONS,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserFieldKey::ROW_KEY => Connection::userId,
                BrowserFieldKey::WHERE => [
                    Connection::userId => ChatBrowserRef::TABLE_USER_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
            ],
        ],
    ];
}

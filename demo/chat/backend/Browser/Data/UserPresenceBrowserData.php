<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Data;

use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Demo\Chat\Pages\UserPage;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;

/**
 * Browser data source for the chat user detail page: the user's reactive
 * runtime presence. The user profile is delivered separately as a page entity
 * by {@see UserPage::buildPagePayload()}.
 */
final class UserPresenceBrowserData
{
    public const string DATA = ChatBrowserData::USER_PRESENCE;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            UserPageSubscribeParams::USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            ChatBrowserSource::RT_CONNECTIONS,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserDataFieldKey::ROW_KEY => Connection::userId,
                BrowserDataFieldKey::WHERE => [
                    Connection::userId => ChatBrowserRef::TABLE_USER_ID,
                ],
                BrowserDataFieldKey::FIELDS => [
                    Connection::userId,
                ],
                BrowserDataFieldKey::COMPUTED => [
                    HilosUserPresenceSummary::presence,
                    HilosUserPresenceSummary::onlineSessionCount,
                ],
            ],
        ],
    ];
}

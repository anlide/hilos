<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Database\Object\Item\User;

/**
 * Browser list of the one user of the /user/{id} page, keeping their profile fresh on the open page.
 */
final class UserProfileBrowserList
{
    public const string LIST = ChatBrowserList::USER_PROFILE;

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            UserPageSubscribeParams::USER_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::DB_USERS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_USERS,
                BrowserListFieldKey::ITEM_KEY => User::id,
                BrowserListFieldKey::WHERE => [
                    User::id => ChatBrowserRef::TABLE_USER_ID,
                ],
                BrowserListFieldKey::FIELDS => [
                    User::id,
                    User::name,
                    User::lastActivity,
                ],
            ],
        ],
    ];
}

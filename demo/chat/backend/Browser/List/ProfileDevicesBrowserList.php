<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Object\Item\PushSubscription;

/** Browser list of the signed-in user's push devices, including expired rows. */
final class ProfileDevicesBrowserList
{
    public const string LIST = ChatBrowserList::PROFILE_DEVICES;
    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::RT_CONNECTIONS,
            ChatBrowserSource::DB_PUSH_SUBSCRIPTIONS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::WHERE => [Connection::acceptKey => ChatBrowserRef::TABLE_ACCEPT_KEY],
                BrowserListFieldKey::FIELDS => [Connection::userId],
            ],
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_PUSH_SUBSCRIPTIONS,
                BrowserListFieldKey::ITEM_KEY => PushSubscription::userId,
                BrowserListFieldKey::MANY => true,
                BrowserListFieldKey::VIA => [PushSubscription::userId => Connection::userId],
                BrowserListFieldKey::FIELDS => [
                    PushSubscription::id,
                    PushSubscription::deviceName,
                    PushSubscription::endpointHash,
                    PushSubscription::createdAt,
                    PushSubscription::goneAt,
                ],
            ],
        ],
    ];
}

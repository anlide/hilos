<?php

declare(strict_types=1);

namespace Demo\Pmd\Browser\List;

use Demo\Pmd\Browser\PmdBrowserRef;
use Demo\Pmd\Browser\PmdBrowserSource;
use Demo\Pmd\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Database\Object\Item\PushSubscription;

/** Browser list of the signed-in user's push devices, including expired rows. */
final class ProfileDevicesBrowserList
{
    public const string LIST = 'profileDevices';

    public const array BINDING = [
        BrowserParamKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => PmdBrowserRef::ACCEPT_KEY,
        ],
    ];

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            PmdBrowserSource::RT_CONNECTIONS,
            PmdBrowserSource::DB_PUSH_SUBSCRIPTIONS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => PmdBrowserSource::RT_CONNECTIONS,
                BrowserListFieldKey::ITEM_KEY => Connection::userId,
                BrowserListFieldKey::WHERE => [Connection::acceptKey => PmdBrowserRef::TABLE_ACCEPT_KEY],
                BrowserListFieldKey::FIELDS => [Connection::userId],
                BrowserListFieldKey::TRIGGERS => [Connection::userId],
            ],
            [
                BrowserListFieldKey::SOURCE => PmdBrowserSource::DB_PUSH_SUBSCRIPTIONS,
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

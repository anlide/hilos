<?php

declare(strict_types=1);

namespace Demo\Polls\Browser\Data;

use Demo\Polls\Browser\PollsBrowserData;
use Demo\Polls\Browser\PollsBrowserRef;
use Demo\Polls\Browser\PollsBrowserSource;
use Demo\Polls\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Browser data source for current WebSocket connection state.
 */
final class SelfConnectionBrowserData
{
    public const string DATA = PollsBrowserData::SELF_CONNECTION;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            PollsBrowserSource::RT_CONNECTIONS,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => PollsBrowserSource::RT_CONNECTIONS,
                BrowserDataFieldKey::ROW_KEY => Connection::userId,
                BrowserDataFieldKey::WHERE => [
                    Connection::acceptKey => PollsBrowserRef::TABLE_ACCEPT_KEY,
                ],
                BrowserDataFieldKey::FIELDS => [
                    Connection::acceptKey,
                    Connection::userId,
                    Connection::connectedAt,
                    Connection::sessionId,
                ],
                BrowserDataFieldKey::TRIGGERS => [
                    Connection::userId,
                    Connection::connectedAt,
                    Connection::sessionId,
                ],
            ],
        ],
    ];
}

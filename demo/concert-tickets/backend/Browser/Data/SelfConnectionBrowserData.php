<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Browser\Data;

use Demo\ConcertTickets\Browser\ConcertTicketsBrowserData;
use Demo\ConcertTickets\Browser\ConcertTicketsBrowserRef;
use Demo\ConcertTickets\Browser\ConcertTicketsBrowserSource;
use Demo\ConcertTickets\Runtime\State\Item\Connection;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Browser data source for current WebSocket connection state.
 * The connectedAt field carries UTC epoch milliseconds directly from the runtime row.
 */
final class SelfConnectionBrowserData
{
    public const string DATA = ConcertTicketsBrowserData::SELF_CONNECTION;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            ConcertTicketsBrowserSource::RT_CONNECTIONS,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => ConcertTicketsBrowserSource::RT_CONNECTIONS,
                BrowserDataFieldKey::ROW_KEY => Connection::userId,
                BrowserDataFieldKey::WHERE => [
                    Connection::acceptKey => ConcertTicketsBrowserRef::TABLE_ACCEPT_KEY,
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

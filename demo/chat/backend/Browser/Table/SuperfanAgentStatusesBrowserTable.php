<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;

/**
 * Browser table config for superfan agent run statuses.
 */
final class SuperfanAgentStatusesBrowserTable
{
    public const string TABLE = ChatBrowserTable::SUPERFAN_AGENT_STATUSES;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::RT_SUPERFAN_AGENT_STATUSES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_SUPERFAN_AGENT_STATUSES,
                BrowserTableFieldKey::ROW_KEY => SuperfanAgentStatus::agentId,
                BrowserTableFieldKey::FIELDS => [
                    SuperfanAgentStatus::agentId,
                    SuperfanAgentStatus::status,
                    SuperfanAgentStatus::updatedAt,
                ],
                BrowserTableFieldKey::NOT_PERSONAL => [
                    SuperfanAgentStatus::agentId,
                    SuperfanAgentStatus::status,
                    SuperfanAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Runtime\State\Item\SuperfanAgentStatus;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;

/**
 * Browser table config for one superfan agent run status.
 */
final class SuperfanAgentStatusDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::SUPERFAN_AGENT_STATUS_DETAIL;

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_SUPERFAN_AGENT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::RT_SUPERFAN_AGENT_STATUSES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_SUPERFAN_AGENT_STATUSES,
                BrowserTableFieldKey::ROW_KEY => SuperfanAgentStatus::agentId,
                BrowserTableFieldKey::WHERE => [
                    SuperfanAgentStatus::agentId => ChatBrowserRef::TABLE_HILOS_SUPERFAN_AGENT_ID,
                ],
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

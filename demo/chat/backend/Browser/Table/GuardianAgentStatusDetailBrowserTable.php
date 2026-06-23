<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;

/**
 * Browser table config for one guardian agent run status.
 */
final class GuardianAgentStatusDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::GUARDIAN_AGENT_STATUS_DETAIL;

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => [
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
                BrowserTableFieldKey::ROW_KEY => GuardianAgentStatus::agentId,
                BrowserTableFieldKey::WHERE => [
                    GuardianAgentStatus::agentId => ChatBrowserRef::TABLE_HILOS_GUARDIAN_AGENT_ID,
                ],
                BrowserTableFieldKey::FIELDS => [
                    GuardianAgentStatus::agentId,
                    GuardianAgentStatus::status,
                    GuardianAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

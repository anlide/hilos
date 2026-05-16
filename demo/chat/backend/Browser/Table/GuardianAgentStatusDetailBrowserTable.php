<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;

/**
 * Browser table config for one guardian agent run status.
 */
final class GuardianAgentStatusDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::GUARDIAN_AGENT_STATUS_DETAIL;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID => [
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
                BrowserFieldKey::ROW_KEY => GuardianAgentStatus::agentId,
                BrowserFieldKey::WHERE => [
                    GuardianAgentStatus::agentId => ChatBrowserRef::TABLE_HILOS_GUARDIAN_AGENT_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    GuardianAgentStatus::agentId,
                    GuardianAgentStatus::status,
                    GuardianAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

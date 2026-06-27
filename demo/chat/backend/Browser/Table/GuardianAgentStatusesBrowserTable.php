<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;

/**
 * Browser table config for guardian agent run statuses.
 */
final class GuardianAgentStatusesBrowserTable
{
    public const string TABLE = ChatBrowserTable::GUARDIAN_AGENT_STATUSES;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
                BrowserTableFieldKey::ROW_KEY => GuardianAgentStatus::agentId,
                BrowserTableFieldKey::FIELDS => [
                    GuardianAgentStatus::agentId,
                    GuardianAgentStatus::status,
                    GuardianAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

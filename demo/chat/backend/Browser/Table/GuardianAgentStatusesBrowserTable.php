<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Runtime\State\Item\GuardianAgentStatus;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;

/**
 * Browser table config for guardian agent run statuses.
 */
final class GuardianAgentStatusesBrowserTable
{
    public const string TABLE = ChatBrowserTable::GUARDIAN_AGENT_STATUSES;

    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_GUARDIAN_AGENT_STATUSES,
                BrowserFieldKey::ROW_KEY => GuardianAgentStatus::agentId,
                BrowserFieldKey::FIELDS => [
                    GuardianAgentStatus::agentId,
                    GuardianAgentStatus::status,
                    GuardianAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

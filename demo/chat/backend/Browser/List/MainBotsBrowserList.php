<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Demo\Chat\Tables\Bot\BotTableRow;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;

/**
 * Browser list source for bots visible on the main chat page.
 */
final class MainBotsBrowserList
{
    public const string LIST = ChatBrowserList::MAIN_BOTS;

    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
            ChatBrowserSource::RT_BOT_AGENT_STATUSES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserFieldKey::ROW_KEY => Bot::id,
                BrowserFieldKey::WHERE => [
                    Bot::active => true,
                ],
                BrowserFieldKey::FIELDS => [
                    Bot::id => BotTableRow::id,
                    Bot::name => BotTableRow::name,
                    Bot::description => BotTableRow::description,
                    Bot::style => BotTableRow::style,
                    Bot::topics => BotTableRow::topics,
                    Bot::personality => BotTableRow::personality,
                    Bot::active => BotTableRow::active,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_BOT_AGENT_STATUSES,
                BrowserFieldKey::ROW_KEY => BotAgentStatus::botId,
                BrowserFieldKey::FIELDS => [
                    BotAgentStatus::botId,
                    BotAgentStatus::status,
                    BotAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

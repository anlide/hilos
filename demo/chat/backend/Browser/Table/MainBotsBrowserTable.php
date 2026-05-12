<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Demo\Chat\Tables\Bot\BotTableRow;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;

/**
 * Browser table config for bots visible on the main chat page.
 */
final class MainBotsBrowserTable
{
    public const string TABLE = ChatBrowserTable::MAIN_BOTS;

    public const array BROWSER = [
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
            ChatBrowserSource::RT_BOT_AGENT_STATUSES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
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
                BrowserFieldKey::FIELDS => [
                    BotAgentStatus::botId,
                    BotAgentStatus::status,
                    BotAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

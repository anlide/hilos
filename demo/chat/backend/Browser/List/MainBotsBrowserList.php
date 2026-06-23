<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Demo\Chat\Tables\Bot\BotTableRow;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;

/**
 * Browser list source for bots visible on the main chat page.
 */
final class MainBotsBrowserList
{
    public const string LIST = ChatBrowserList::MAIN_BOTS;

    public const array BROWSER = [
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
            ChatBrowserSource::RT_BOT_AGENT_STATUSES,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserListFieldKey::ITEM_KEY => Bot::id,
                BrowserListFieldKey::WHERE => [
                    Bot::active => true,
                ],
                BrowserListFieldKey::FIELDS => [
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
                BrowserListFieldKey::SOURCE => ChatBrowserSource::RT_BOT_AGENT_STATUSES,
                BrowserListFieldKey::ITEM_KEY => BotAgentStatus::botId,
                BrowserListFieldKey::FIELDS => [
                    BotAgentStatus::botId,
                    BotAgentStatus::status,
                    BotAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

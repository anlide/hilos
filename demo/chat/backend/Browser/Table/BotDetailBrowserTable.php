<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Demo\Chat\Tables\Bot\BotTableRow;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;

/**
 * Browser table config for the chat bot detail page.
 */
final class BotDetailBrowserTable
{
    public const string TABLE = ChatBrowserTable::BOT_DETAIL;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            BotPageSubscribeParams::BOT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
            ChatBrowserSource::RT_BOT_AGENT_STATUSES,
        ],
        BrowserConfigKey::ROWS => [
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::RT_BOT_AGENT_STATUSES,
                BrowserFieldKey::ROW_KEY => BotAgentStatus::botId,
                BrowserFieldKey::WHERE => [
                    BotAgentStatus::botId => ChatBrowserRef::TABLE_BOT_ID,
                ],
                BrowserFieldKey::FIELDS => [
                    BotAgentStatus::botId,
                    BotAgentStatus::status,
                    BotAgentStatus::updatedAt,
                ],
            ],
            [
                BrowserFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserFieldKey::ROW_KEY => Bot::id,
                BrowserFieldKey::WHERE => [
                    Bot::id => ChatBrowserRef::TABLE_BOT_ID,
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
        ],
    ];
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Table;

use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Browser\ChatBrowserTable;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;

/**
 * Browser data source for the chat bot detail page: the bot's reactive runtime
 * lifecycle status. The bot profile is delivered separately as a page entity by
 * {@see \Demo\Chat\Pages\BotPage::buildPagePayload()}.
 */
final class BotStatusBrowserData
{
    public const string DATA = ChatBrowserTable::BOT_STATUS;

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            BotPageSubscribeParams::BOT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::SOURCES => [
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
        ],
    ];
}

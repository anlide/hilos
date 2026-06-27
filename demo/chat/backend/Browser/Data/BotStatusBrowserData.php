<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\Data;

use Demo\Chat\Browser\ChatBrowserData;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Runtime\State\Item\BotAgentStatus;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;

/**
 * Browser data source for the chat bot detail page: the bot's reactive runtime
 * lifecycle status. The bot profile is delivered separately as a page entity by
 * {@see \Demo\Chat\Pages\BotPage::buildPagePayload()}.
 */
final class BotStatusBrowserData
{
    public const string DATA = ChatBrowserData::BOT_STATUS;

    public const array BROWSER = [
        BrowserDataConfigKey::PARAMS => [
            BotPageSubscribeParams::BOT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserDataConfigKey::SOURCES => [
            ChatBrowserSource::RT_BOT_AGENT_STATUSES,
        ],
        BrowserDataConfigKey::ROWS => [
            [
                BrowserDataFieldKey::SOURCE => ChatBrowserSource::RT_BOT_AGENT_STATUSES,
                BrowserDataFieldKey::ROW_KEY => BotAgentStatus::botId,
                BrowserDataFieldKey::WHERE => [
                    BotAgentStatus::botId => ChatBrowserRef::TABLE_BOT_ID,
                ],
                BrowserDataFieldKey::FIELDS => [
                    BotAgentStatus::botId,
                    BotAgentStatus::status,
                    BotAgentStatus::updatedAt,
                ],
            ],
        ],
    ];
}

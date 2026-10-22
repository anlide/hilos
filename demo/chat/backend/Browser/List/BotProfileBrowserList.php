<?php

declare(strict_types=1);

namespace Demo\Chat\Browser\List;

use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Browser\ChatBrowserRef;
use Demo\Chat\Browser\ChatBrowserSource;
use Demo\Chat\Database\Object\Item\Bot;
use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;

/**
 * Browser list of the one bot the bot detail page is about: its durable profile,
 * kept current on the open page whether the bot is active or not.
 */
final class BotProfileBrowserList
{
    public const string LIST = ChatBrowserList::BOT_PROFILE;

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            BotPageSubscribeParams::BOT_ID => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            ChatBrowserSource::DB_BOTS,
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => ChatBrowserSource::DB_BOTS,
                BrowserListFieldKey::ITEM_KEY => Bot::id,
                BrowserListFieldKey::WHERE => [
                    Bot::id => ChatBrowserRef::TABLE_BOT_ID,
                ],
                BrowserListFieldKey::FIELDS => [
                    Bot::id,
                    Bot::name,
                    Bot::description,
                    Bot::style,
                    Bot::topics,
                    Bot::personality,
                    Bot::active,
                ],
            ],
        ],
    ];
}

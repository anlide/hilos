<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Demo\Chat\Pages\DTO\BotPageSubscribeParams;
use Demo\Chat\Pages\DTO\UserPageSubscribeParams;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;

/**
 * Reusable references for chat browser configs.
 */
final class ChatBrowserRef
{
    public const array ACCEPT_KEY = [
        BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY,
    ];

    public const array BOT_ID = [
        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
        BrowserRefKey::KEY => BotPageSubscribeParams::BOT_ID,
    ];

    public const array USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
        BrowserRefKey::KEY => UserPageSubscribeParams::USER_ID,
    ];

    public const array HILOS_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];

    public const array HILOS_GUARDIAN_AGENT_ID = [
        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID,
    ];

    public const array TABLE_ACCEPT_KEY = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
    ];

    public const array TABLE_BOT_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => BotPageSubscribeParams::BOT_ID,
    ];

    public const array TABLE_HILOS_USER_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_USER_USER_ID,
    ];

    public const array TABLE_HILOS_GUARDIAN_AGENT_ID = [
        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
        BrowserRefKey::KEY => HilosPageRouteParams::HILOS_GUARDIAN_AGENT_AGENT_ID,
    ];
}

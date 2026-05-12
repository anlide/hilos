<?php

declare(strict_types=1);

namespace Demo\Chat\Browser;

use Hilos\Core\Browser\Context\BrowserContext;

/**
 * Chat demo browser-facing context.
 */
final class ChatBrowserContext extends BrowserContext
{
    /**
     * Registers chat browser-facing state helpers.
     */
    public function configure(): void
    {
    }
}

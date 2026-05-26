<?php

declare(strict_types=1);

namespace Demo\Chat\Agents\Hilos;

use Demo\Chat\Database\ChatDbContext;
use Hilos\Core\Agent\Hilos\AbstractHilosIndexAgent;

/**
 * DemoHilosAgent - Concrete Hilos index agent for chat demo.
 *
 * Handles Hilos dashboard, settings and i18n pages in the demo project.
 */
final class DemoHilosAgent extends AbstractHilosIndexAgent
{
    /**
     * Registers framework and chat-admin truth sources used by Hilos index pages.
     */
    public function onStart(): void
    {
        parent::onStart();

        $this->registerDbTruthSource(ChatDbContext::users);
        $this->registerDbTruthSource(ChatDbContext::events);
        $this->registerDbTruthSource(ChatDbContext::eventUserRenames);
    }
}

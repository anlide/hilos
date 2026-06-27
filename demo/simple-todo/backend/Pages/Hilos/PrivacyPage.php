<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Pages\Hilos;

use Demo\SimpleTodo\Constants\AgentType;
use Hilos\Pages\AbstractHilosPrivacyPage;

/**
 * PrivacyPage - Privacy page implementation for the simple-todo demo.
 *
 * Static, content-only: the framework page sends no payload; the visible
 * content is the frontend view. Only the owning agent type is bound here.
 */
final class PrivacyPage extends AbstractHilosPrivacyPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

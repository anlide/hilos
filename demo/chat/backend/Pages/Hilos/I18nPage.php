<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\AbstractHilosI18nPage;

/**
 * I18nPage - I18n page implementation for demo.
 */
final class I18nPage extends AbstractHilosI18nPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

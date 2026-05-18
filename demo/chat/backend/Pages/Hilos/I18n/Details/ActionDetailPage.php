<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Details;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Details\AbstractHilosI18nActionPage;

/**
 * ActionDetailPage - Action detail page implementation for demo.
 */
final class ActionDetailPage extends AbstractHilosI18nActionPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

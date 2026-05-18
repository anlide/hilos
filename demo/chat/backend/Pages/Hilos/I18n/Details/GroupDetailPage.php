<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Details;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Details\AbstractHilosI18nGroupPage;

/**
 * GroupDetailPage - Group detail page implementation for demo.
 */
final class GroupDetailPage extends AbstractHilosI18nGroupPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

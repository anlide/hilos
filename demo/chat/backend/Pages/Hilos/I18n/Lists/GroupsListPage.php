<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Lists;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nGroupsListPage;

/**
 * GroupsListPage - Groups list page implementation for demo.
 */
final class GroupsListPage extends AbstractHilosI18nGroupsListPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

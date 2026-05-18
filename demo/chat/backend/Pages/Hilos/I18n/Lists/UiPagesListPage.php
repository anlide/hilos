<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Lists;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nUiPagesListPage;

/**
 * UiPagesListPage - UI pages list page implementation for demo.
 */
final class UiPagesListPage extends AbstractHilosI18nUiPagesListPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

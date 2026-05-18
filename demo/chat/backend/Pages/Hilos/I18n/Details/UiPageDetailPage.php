<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Details;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Details\AbstractHilosI18nUiPagePage;

/**
 * UiPageDetailPage - UI page detail implementation for demo.
 */
final class UiPageDetailPage extends AbstractHilosI18nUiPagePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

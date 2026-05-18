<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Translate;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Translate\AbstractHilosI18nTranslateGroupItemPage;

/**
 * TranslateGroupItemPage - Translate group item page implementation for demo.
 */
final class TranslateGroupItemPage extends AbstractHilosI18nTranslateGroupItemPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Translate;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Translate\AbstractHilosI18nTranslateUiPageScreenPage;

/**
 * TranslateUiPagePage - Translate UI page implementation for demo.
 */
final class TranslateUiPagePage extends AbstractHilosI18nTranslateUiPageScreenPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

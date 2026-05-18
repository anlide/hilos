<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Translate;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Translate\AbstractHilosI18nTranslateUiPageItemPage;

/**
 * TranslateUiPageItemPage - Translate UI page item implementation for demo.
 */
final class TranslateUiPageItemPage extends AbstractHilosI18nTranslateUiPageItemPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

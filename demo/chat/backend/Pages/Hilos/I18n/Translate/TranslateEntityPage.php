<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Translate;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Translate\AbstractHilosI18nTranslateEntityPage;

/**
 * TranslateEntityPage - Translate entity page implementation for demo.
 */
final class TranslateEntityPage extends AbstractHilosI18nTranslateEntityPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

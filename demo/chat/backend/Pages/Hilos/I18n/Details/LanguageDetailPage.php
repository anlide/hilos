<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\I18n\Details;

use Demo\Chat\Constants\AgentType;
use Hilos\Pages\I18n\Details\AbstractHilosI18nLanguagePage;

/**
 * LanguageDetailPage - Language detail page implementation for demo.
 */
final class LanguageDetailPage extends AbstractHilosI18nLanguagePage
{
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::HILOS_INDEX;
}

<?php

declare(strict_types=1);

namespace Demo\Tasks\Pages\Hilos\I18n\Details;

use Hilos\Constants\HilosAgentType;
use Hilos\Pages\I18n\Details\AbstractHilosI18nCountryPage;

/** Binds the framework i18n page to the library owner. */
final class CountryDetailPage extends AbstractHilosI18nCountryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}

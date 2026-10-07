<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Pages\AbstractHilosI18nPage;

/** Binds the framework i18n page to the library owner. */
final class I18nPage extends AbstractHilosI18nPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}

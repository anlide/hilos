<?php

declare(strict_types=1);

namespace Demo\Polls\Pages\Hilos\I18n\Lists;

use Hilos\Constants\HilosAgentType;
use Hilos\Pages\I18n\Lists\AbstractHilosI18nLanguagesListPage;

/** Binds the framework i18n page to the library owner. */
final class LanguagesListPage extends AbstractHilosI18nLanguagesListPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_I18N_LIBRARY;
}

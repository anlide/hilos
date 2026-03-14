<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosPageConstants - Page constants for Hilos admin section.
 *
 * Defines page identifiers for framework-level Hilos admin pages.
 * Projects inherit these pages via HilosPageFactory.
 */
class HilosPageConstants
{
    /** @var string Hilos dashboard (main page of hilos section) */
    public const string HILOS_DASHBOARD = 'hilos';

    /** @var string Hilos settings page */
    public const string HILOS_SETTINGS = 'hilos_settings';

    /** @var string Hilos internationalization page (languages, countries, locales, translations) */
    public const string HILOS_I18N = 'hilos_i18n';

    /** @var string Hilos guardian page (project validation robots) */
    public const string HILOS_GUARDIAN = 'hilos_guardian';

    /** @var string Hilos analytics page (visit statistics) */
    public const string HILOS_ANALYTICS = 'hilos_analytics';
}

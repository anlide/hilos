<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosSignalConstants - Signal constants for Hilos admin pages.
 *
 * Defines signal names used by framework-level Hilos admin pages.
 */
class HilosSignalConstants
{
    /** @var string Subscription signal for Hilos dashboard page */
    public const string SUBSCRIPTION_PAGE_HILOS_DASHBOARD = 'subscription_page_hilos';

    /** @var string Subscription signal for Hilos settings page */
    public const string SUBSCRIPTION_PAGE_HILOS_SETTINGS = 'subscription_page_hilos_settings';

    /** @var string Subscription signal for Hilos i18n page */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N = 'subscription_page_hilos_i18n';

    /** @var string Subscription signal for Hilos guardian page */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN = 'subscription_page_hilos_guardian';

    /** @var string Subscription signal for Hilos guardian AI agent page */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT = 'subscription_page_hilos_guardian_agent';

    /** @var string Subscription signal for Hilos analytics page */
    public const string SUBSCRIPTION_PAGE_HILOS_ANALYTICS = 'subscription_page_hilos_analytics';
}

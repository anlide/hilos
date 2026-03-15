<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Constants\HilosPageConstants;

/**
 * PageConstants - Page constants for chat demo.
 *
 * Defines page identifiers used for user subscriptions.
 */
final class PageConstants
{
    /** @var string Chat main page */
    public const string MAIN = 'main';

    /** @var string User profile page */
    public const string PROFILE = 'profile';

    /** @var string User page */
    public const string USER = 'user';

    /** @var string Bot page */
    public const string BOT = 'bot';

    /** @var string Moderator page */
    public const string MODERATOR = 'moderator';

    /** @var string Admin page */
    public const string ADMIN = 'admin';

    /** @var string Admin users page */
    public const string ADMIN_USERS = 'admin_users';

    /** @var string Admin moderator page */
    public const string ADMIN_MODERATOR = 'admin_moderator';

    /** @var string Admin bots page */
    public const string ADMIN_BOTS = 'admin_bots';

    /** @var string Hilos dashboard page */
    public const string HILOS_DASHBOARD = HilosPageConstants::HILOS_DASHBOARD;

    /** @var string Hilos settings page */
    public const string HILOS_SETTINGS = HilosPageConstants::HILOS_SETTINGS;

    /** @var string Hilos internationalization page */
    public const string HILOS_I18N = HilosPageConstants::HILOS_I18N;

    /** @var string Hilos guardian page */
    public const string HILOS_GUARDIAN = HilosPageConstants::HILOS_GUARDIAN;

    /** @var string Hilos analytics page */
    public const string HILOS_ANALYTICS = HilosPageConstants::HILOS_ANALYTICS;
}

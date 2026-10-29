<?php

declare(strict_types=1);

namespace Demo\Tasks\Constants;

use Hilos\Constants\HilosPageConstants;

/**
 * PageConstants - Page constants for the tasks demo.
 *
 * Defines page identifiers used for user subscriptions.
 */
final class PageConstants
{
    /** @var string Hilos public Terms history page */
    public const string HILOS_TERMS_HISTORY = HilosPageConstants::HILOS_TERMS_HISTORY;

    /** @var string Hilos public Privacy history page */
    public const string HILOS_PRIVACY_HISTORY = HilosPageConstants::HILOS_PRIVACY_HISTORY;

    public const string HILOS_CHANGE_LOG = HilosPageConstants::HILOS_CHANGE_LOG;

    public const string HILOS_CHANGE_LOG_TABLES = HilosPageConstants::HILOS_CHANGE_LOG_TABLES;

    public const string HILOS_CHANGE_LOG_TABLE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    /** @var string Tasks main page */
    public const string MAIN = 'main';
}

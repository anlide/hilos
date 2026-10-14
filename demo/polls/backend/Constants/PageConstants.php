<?php

declare(strict_types=1);

namespace Demo\Polls\Constants;

use Hilos\Constants\HilosPageConstants;

/**
 * PageConstants - Page constants for the polls demo.
 *
 * Defines page identifiers used for user subscriptions.
 */
final class PageConstants
{
    public const string HILOS_CHANGE_LOG = HilosPageConstants::HILOS_CHANGE_LOG;

    public const string HILOS_CHANGE_LOG_TABLES = HilosPageConstants::HILOS_CHANGE_LOG_TABLES;

    public const string HILOS_CHANGE_LOG_TABLE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    /** @var string Polls main page */
    public const string MAIN = 'main';
}

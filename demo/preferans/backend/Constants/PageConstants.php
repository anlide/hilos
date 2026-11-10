<?php

declare(strict_types=1);

namespace Demo\Preferans\Constants;

use Hilos\Constants\HilosPageConstants;

/**
 * PageConstants - Page constants for the preferans demo.
 *
 * Defines page identifiers used for user subscriptions.
 */
final class PageConstants
{
    /** @var string Hilos public Terms history page */
    public const string HILOS_TERMS_HISTORY = HilosPageConstants::HILOS_TERMS_HISTORY;

    /** @var string Hilos public Privacy history page */
    public const string HILOS_PRIVACY_HISTORY = HilosPageConstants::HILOS_PRIVACY_HISTORY;

    /** @var string Preferans main page */
    public const string MAIN = 'main';
}

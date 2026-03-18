<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * Framework-level HTTP header names shared across demos and projects.
 *
 * Provides header identifiers used by Hilos analytics and other framework features.
 */
final class HilosHttpHeaders
{
    /** @var string Browser session token used for analytics correlation */
    public const string HILOS_SESSION_TOKEN = 'Hilos-Session-Token';
}

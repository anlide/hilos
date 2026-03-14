<?php

declare(strict_types=1);

namespace Hilos\Guardian\Telemetry;

use Hilos\Utils\Logger;

/**
 * Guardian telemetry logging helper.
 */
final class GuardianTelemetry
{
    /**
     * Log guardian telemetry event.
     *
     * @param string $name Event name
     * @param array<string, mixed> $context Event context data
     */
    public static function event(string $name, array $context = []): void
    {
        Logger::debug('[guardian.telemetry] ' . $name . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

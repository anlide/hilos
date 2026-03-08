<?php

declare(strict_types=1);

namespace Hilos\Guardian\Telemetry;

use Hilos\Utils\Logger;

final class GuardianTelemetry
{
    /**
     * @param array<string, mixed> $context
     */
    public static function event(string $name, array $context = []): void
    {
        Logger::debug('[guardian.telemetry] ' . $name . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

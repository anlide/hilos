<?php

declare(strict_types=1);

namespace Hilos\Environment;

use DateTimeZone;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/**
 * Refuses a project time zone that is not an exact IANA name.
 *
 * Reads the catalog accessor and nothing else: no second getter and no remembered
 * answer. The process clock is not this class's to set, and the name is not rewritten.
 */
final class ProjectTimeZoneStartupGuard
{
    /**
     * Refuses startup when the configured project zone is not an IANA name.
     *
     * An absent value is the catalog default, UTC. An explicit empty string, an
     * unknown name, surrounding whitespace and a fixed offset are refused with
     * the original value.
     *
     * @throws EnvException When the configured key is absent from the environment catalog
     * @throws EnvInvalidValueException When the value is not an exact IANA time zone name
     */
    public static function assertConfigured(): void
    {
        $zone = Hilos::$env[EnvConstants::HILOS_PROJECT_TIME_ZONE]->string();
        if (in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return;
        }

        throw new EnvInvalidValueException(
            "Environment variable 'HILOS_PROJECT_TIME_ZONE' must name an IANA time zone; got '{$zone}'",
        );
    }
}

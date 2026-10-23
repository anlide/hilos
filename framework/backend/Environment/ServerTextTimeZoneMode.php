<?php

declare(strict_types=1);

namespace Hilos\Environment;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;

/** The installation's time zone policy for text addressed to a person. */
enum ServerTextTimeZoneMode: string
{
    case RECIPIENT = 'recipient';
    case PROJECT = 'project';
    case UTC = 'utc';

    /**
     * Accepts only exact mode names; an absent value uses the catalog default.
     *
     * @return self Configured server text policy
     * @throws EnvException When the catalog accessor refuses the configured key or value
     * @throws EnvInvalidValueException When the value is not recipient, project or utc
     */
    public static function fromEnv(): self
    {
        $value = Hilos::$env[EnvConstants::HILOS_SERVER_TEXT_TIME_ZONE_MODE]->string();

        return self::tryFrom($value) ?? throw new EnvInvalidValueException(
            "Environment variable 'HILOS_SERVER_TEXT_TIME_ZONE_MODE' must be recipient, project or utc; got '{$value}'",
        );
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * AppEnv - Canonical application environment values
 *
 * Standard environment identifiers for APP_ENV. Use these values in .env
 * to ensure consistent behavior (e.g. database seeds disabled on PROD and STAGING).
 *
 * Aliases are supported when parsing: "production", "prod", "live" -> PROD, etc.
 */
enum AppEnv: string
{
    /** Production (live). Seeds and destructive operations disabled. */
    case PROD = 'prod';

    /** Staging (pre-production). Often treated like PROD. */
    case STAGING = 'staging';

    /** Development (local work). Full features. */
    case DEV = 'dev';

    /** Local (machine/dev container). Full features. */
    case LOCAL = 'local';

    /** Test (CI, automated tests). Full features. */
    case TEST = 'test';

    /**
     * Map: canonical value (enum case value) -> aliases including canonical.
     *
     * @return array<string, list<string>> Mapping from canonical value to alias list
     */
    private static function aliases(): array
    {
        return [
            self::PROD->value => [self::PROD->value, 'production', 'live', 'pro'],
            self::STAGING->value => [self::STAGING->value, 'stage', 'stg'],
            self::DEV->value => [self::DEV->value, 'development'],
            self::LOCAL->value => [self::LOCAL->value, 'localhost'],
            self::TEST->value => [self::TEST->value, 'testing', 'ci'],
        ];
    }

    /**
     * Parse APP_ENV string into AppEnv.
     *
     * Accepts canonical values and common aliases (case-insensitive).
     *
     * @param ?string $value Raw APP_ENV value
     * @return ?self Matched enum case or null when unrecognized
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));

        foreach (self::aliases() as $canonical => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return self::from($canonical);
            }
        }

        return null;
    }

    /**
     * Whether seeds are allowed in this environment.
     *
     * @return bool False for PROD and STAGING
     */
    public function isSeedsAllowed(): bool
    {
        return $this !== self::PROD && $this !== self::STAGING;
    }

    /**
     * Whether this is a production-like environment (PROD or STAGING).
     *
     * @return bool True for PROD, STAGING
     */
    public function isProductionLike(): bool
    {
        return !$this->isSeedsAllowed();
    }
}

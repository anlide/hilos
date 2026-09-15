<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * SessionCookieName - the single owner of the session cookie's name (HIL-904).
 *
 * Derives the cookie name from the installation's declared database when no explicit
 * override is configured, so two Hilos installations answering on one hostname do not
 * overwrite each other's cookie slot.
 *
 * The explicit override (HILOS_SESSION_COOKIE_NAME) is returned verbatim when non-empty.
 * When unset or blank, derivation hashes the declared database name (DB_DATABASE, else
 * DB_NAME) and appends the first 8 lowercase hex characters to the derived prefix.
 */
final class SessionCookieName
{
    /** @var string Prefix for derived session cookie names */
    public const string DERIVED_PREFIX = 'hilos_session_token_';

    /** @var int Number of lowercase hex characters taken from the database hash */
    private const int HASH_LENGTH = 8;

    /**
     * Resolves the session cookie name for this installation.
     *
     * Returns the explicit HILOS_SESSION_COOKIE_NAME override verbatim when non-empty,
     * else derives a deterministic name from the declared database.
     *
     * @return string Resolved session cookie name
     * @throws EnvException When an environment value cannot be read
     */
    public static function resolve(): string
    {
        $override = Hilos::$env[EnvConstants::HILOS_SESSION_COOKIE_NAME]->string();
        if ($override !== '') {
            return $override;
        }

        $database = Hilos::$env[EnvConstants::DB_DATABASE]->string();
        if ($database === '') {
            $database = Hilos::$env[EnvConstants::DB_NAME]->string();
        }

        return self::derive($database);
    }

    /**
     * Predicate indicating whether an explicit cookie name override is configured.
     *
     * @return bool True when HILOS_SESSION_COOKIE_NAME is set to a non-empty string
     * @throws EnvException When the environment value cannot be read
     */
    public static function isOverridden(): bool
    {
        return Hilos::$env[EnvConstants::HILOS_SESSION_COOKIE_NAME]->string() !== '';
    }

    /**
     * Derives a deterministic session cookie name from the declared database name.
     *
     * @param string $databaseName Declared database name
     * @return string Derived session cookie name
     */
    public static function derive(string $databaseName): string
    {
        return self::DERIVED_PREFIX . substr(hash('sha256', $databaseName), 0, self::HASH_LENGTH);
    }
}

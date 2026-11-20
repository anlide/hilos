<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;

/**
 * Project facade fixture that registers nobody ({@see AuthMethodTestClosedDirectory}).
 *
 * Its provider directory declares providers the method directory does not wire, so a case
 * can tell a declared provider from a wired one. Mounted and unmounted like
 * {@see AuthMethodTestHilos}, whose settings it shares.
 */
final class AuthMethodTestClosedHilos extends Hilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthMethodTestClosedDirectory::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = AuthMethodTestProviderDirectory::class;

    /**
     * Mounts this facade with the switched-off method list a case stores.
     *
     * @param ?string $disabled Stored switched-off list, or null for no stored row
     */
    public static function mount(?string $disabled): void
    {
        AuthMethodTestSettings::$disabled = $disabled;
        static::$setting = new AuthMethodTestSettings();
        static::initBrowser();
    }

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new AuthMethodTestDbContext();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;

/**
 * Project facade fixture wiring only methods that need something set up ({@see AuthMethodTestUnservedDirectory}).
 *
 * Mounted and unmounted like {@see AuthMethodTestHilos}, whose settings it shares.
 */
final class AuthMethodTestUnservedHilos extends Hilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthMethodTestUnservedDirectory::class;

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

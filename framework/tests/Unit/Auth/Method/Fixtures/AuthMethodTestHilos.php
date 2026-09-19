<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Method\Fixtures;

use Hilos\Database\Context\DbContext;
use Hilos\Hilos;

/**
 * Project facade fixture pointing the method and provider directories at the test ones.
 *
 * The directories are reached through the captured project facade, which is what a real
 * installation does, so each case mounts this facade and the settings it holds, and
 * unmounts both: the suite runs in one process and that capture is global.
 */
final class AuthMethodTestHilos extends Hilos
{
    protected const string AUTH_METHOD_DIRECTORY = AuthMethodTestDirectory::class;

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
     * Puts the base facade back, with no settings and nothing stored.
     */
    public static function unmount(): void
    {
        AuthMethodTestSettings::$disabled = null;
        static::$setting = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();
    }

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new AuthMethodTestDbContext();
    }
}

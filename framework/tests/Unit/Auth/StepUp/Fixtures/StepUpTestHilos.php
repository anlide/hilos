<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\StepUp\Fixtures;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;

/**
 * Project facade fixture pointing at the test operation directory and settings.
 */
final class StepUpTestHilos extends Hilos
{
    protected const string STEP_UP_OPERATION_DIRECTORY = StepUpTestDirectory::class;

    /**
     * @param ?string $disabled Stored switched-off list, or null for no stored row
     */
    public static function mount(?string $disabled): void
    {
        StepUpTestSettings::$disabled = $disabled;
        static::$setting = new StepUpTestSettings();
        static::initBrowser();
    }

    /**
     * Restores the base facade and clears scripted settings.
     */
    public static function unmount(): void
    {
        StepUpTestSettings::$disabled = null;
        static::$setting = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();
    }

    /**
     * @return HilosDbContext No-op test database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new StepUpTestDbContext();
    }
}

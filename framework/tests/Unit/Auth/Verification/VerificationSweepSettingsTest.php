<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Verification;

use Hilos\Auth\Verification\VerificationSweepSettings;
use Hilos\Auth\Verification\VerificationSweepSettingsCatalog;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** Reads live retention and schedule values with safe defaults (HIL-1163). */
final class VerificationSweepSettingsTest extends TestCase
{
    private ?SettingsAccessor $previousSetting = null;
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousSetting = Hilos::$setting;
        $this->previousEnv = Hilos::$env;
        Hilos::$setting = null;
        Hilos::$env = null;
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSetting;
        Hilos::$env = $this->previousEnv;
    }

    public function testNoAccessorAnswersTheSendWindowAndDefaultSchedule(): void
    {
        self::assertSame(3600, VerificationSweepSettings::retentionSeconds());
        self::assertSame('*/10 * * * *', VerificationSweepSettings::sweepCron());
    }

    public function testStoredRetentionIsFlooredAtTheSendWindow(): void
    {
        Hilos::$setting = self::storing('120', '5 * * * *');
        self::assertSame(3600, VerificationSweepSettings::retentionSeconds());
        self::assertSame('5 * * * *', VerificationSweepSettings::sweepCron());

        Hilos::$setting = self::storing('7200', '5 * * * *');
        self::assertSame(7200, VerificationSweepSettings::retentionSeconds());
    }

    public function testEmptyAndInvalidStoredSchedulesFallBack(): void
    {
        Hilos::$setting = self::storing('3600', '');
        self::assertSame(VerificationSweepSettings::DEFAULT_SWEEP_CRON, VerificationSweepSettings::sweepCron());

        Hilos::$setting = self::storing('3600', 'bad schedule');
        self::assertSame(VerificationSweepSettings::DEFAULT_SWEEP_CRON, VerificationSweepSettings::sweepCron());
    }

    /**
     * @param string $retention Stored retention
     * @param string $cron Stored schedule
     * @return SettingsAccessor Scripted settings reader
     */
    private static function storing(string $retention, string $cron): SettingsAccessor
    {
        return new class ($retention, $cron) extends SettingsAccessor {
            public function __construct(private readonly string $retention, private readonly string $cron)
            {
                parent::__construct(VerificationSweepSettingsCatalog::class);
            }

            /**
             * @param string $key Setting key
             * @return mixed Scripted stored value or the catalog default
             */
            public function effectiveValueFor(string $key): mixed
            {
                return match ($key) {
                    VerificationSweepSettings::RETENTION_SECONDS_KEY => $this->retention,
                    VerificationSweepSettings::SWEEP_CRON_KEY => $this->cron,
                    default => parent::effectiveValueFor($key),
                };
            }
        };
    }
}

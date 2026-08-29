<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Hilos;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogSettingsResolver;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the settings-over-environment reader of the log policies (HIL-760).
 *
 * Locks the four ways a value is arrived at — a written setting wins, no setting means the
 * environment, a project without the catalog fragment stays on the environment silently, and a
 * value that fails its rule falls back with one complaint — plus the promise that keeps the
 * complaint out of the journal it configures: it is written when the outcome changes, not on
 * every one of the five-second checks.
 */
final class LogSettingsResolverTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        LogSettingsResolverTestAccessor::$values = [];
        Hilos::$setting = new LogSettingsResolverTestAccessor(LogSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;
        LogSettingsResolverTestAccessor::$values = [];

        parent::tearDown();
    }

    public function testWrittenSettingOverridesTheEnvironment(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => '3600',
            LogSettingsCatalog::ROTATION_MAX_LIVE_SIZE_BYTES => '1048576',
            LogSettingsCatalog::ROTATION_CRON => '0 3 * * *',
        ];

        $policy = new LogSettingsResolver()->rotationPolicy();

        $this->assertSame(3600, $policy->maxAgeSeconds);
        $this->assertSame(1_048_576, $policy->maxLiveSizeBytes);
        $this->assertSame('0 3 * * *', $policy->cronExpression);
    }

    public function testWithoutAWrittenSettingTheEnvironmentValueIsUsed(): void
    {
        $resolver = new LogSettingsResolver();

        $policy = $resolver->rotationPolicy();

        $this->assertSame($this->envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS), $policy->maxAgeSeconds);
        $this->assertSame($this->envInt(EnvConstants::LOG_ROTATION_MAX_LIVE_SIZE_BYTES), $policy->maxLiveSizeBytes);
        $this->assertNull($resolver->takeComplaint());
    }

    public function testRetentionReadsTheSameWayAndKeepsItsOwnValues(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ARCHIVE_RETENTION_KEEP_BATCHES => '7',
            LogSettingsCatalog::ARCHIVE_RETENTION_MAX_AGE_SECONDS => '604800',
        ];

        $resolver = new LogSettingsResolver();
        $policy = $resolver->retentionPolicy();

        $this->assertSame(7, $policy->keepBatches);
        $this->assertSame(604_800, $policy->maxAgeSeconds);
        $this->assertNull($resolver->takeComplaint());
    }

    public function testAProjectWithoutTheCatalogFragmentStaysOnTheEnvironmentWithoutComplaint(): void
    {
        Hilos::$setting = new SettingsAccessor(SettingsCatalogStub::class);

        $resolver = new LogSettingsResolver();
        $policy = $resolver->rotationPolicy();

        $this->assertSame($this->envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS), $policy->maxAgeSeconds);
        $this->assertNull($resolver->takeComplaint());
    }

    public function testUninitializedSettingsFallBackToTheEnvironmentAndComplainOnce(): void
    {
        Hilos::$setting = null;

        $resolver = new LogSettingsResolver();
        $policy = $resolver->rotationPolicy();

        $this->assertSame($this->envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS), $policy->maxAgeSeconds);
        $complaint = $resolver->takeComplaint();
        $this->assertNotNull($complaint);
        $this->assertStringContainsString('not initialized', $complaint);
        $this->assertNull($resolver->takeComplaint());
    }

    public function testAValueRefusedByItsRuleFallsBackToTheEnvironment(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => '-5',
        ];

        $resolver = new LogSettingsResolver();
        $policy = $resolver->rotationPolicy();

        $this->assertSame($this->envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS), $policy->maxAgeSeconds);
        $complaint = $resolver->takeComplaint();
        $this->assertNotNull($complaint);
        $this->assertStringContainsString(LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS, $complaint);
    }

    public function testAScheduleThatWouldNeverFireIsRefusedOnTheReadToo(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ROTATION_CRON => '0 3 * * abc',
        ];

        $resolver = new LogSettingsResolver();
        $policy = $resolver->rotationPolicy();

        $this->assertNull($policy->createCronRule());
        $this->assertNotNull($resolver->takeComplaint());
    }

    public function testAFailingReadFallsBackToTheEnvironment(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => new DatabaseException('settings table is gone'),
        ];

        $resolver = new LogSettingsResolver();
        $policy = $resolver->rotationPolicy();

        $this->assertSame($this->envInt(EnvConstants::LOG_ROTATION_MAX_AGE_SECONDS), $policy->maxAgeSeconds);
        $complaint = $resolver->takeComplaint();
        $this->assertNotNull($complaint);
        $this->assertStringContainsString('settings table is gone', $complaint);
    }

    public function testTheSameFaultIsComplainedAboutOnceAndAgainAfterItReturns(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => '-5',
        ];
        $resolver = new LogSettingsResolver();

        $resolver->rotationPolicy();
        $this->assertNotNull($resolver->takeComplaint());

        // Same bad value, four more checks: the journal hears nothing more about it.
        for ($check = 0; $check < 4; $check++) {
            $resolver->rotationPolicy();
        }
        $this->assertNull($resolver->takeComplaint());

        // Fixed, then broken again: the fault is news once more.
        LogSettingsResolverTestAccessor::$values[LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS] = '60';
        $resolver->rotationPolicy();
        $this->assertNull($resolver->takeComplaint());

        LogSettingsResolverTestAccessor::$values[LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS] = '-5';
        $resolver->rotationPolicy();
        $this->assertNotNull($resolver->takeComplaint());
    }

    public function testTroubleInOnePolicyDoesNotSilenceTheOther(): void
    {
        LogSettingsResolverTestAccessor::$values = [
            LogSettingsCatalog::ROTATION_MAX_AGE_SECONDS => '-5',
            LogSettingsCatalog::ARCHIVE_RETENTION_KEEP_BATCHES => '-1',
        ];
        $resolver = new LogSettingsResolver();

        $resolver->rotationPolicy();
        $resolver->retentionPolicy();

        $this->assertNotNull($resolver->takeComplaint());
        $this->assertNotNull($resolver->takeComplaint());
        $this->assertNull($resolver->takeComplaint());

        $resolver->rotationPolicy();
        $resolver->retentionPolicy();
        $this->assertNull($resolver->takeComplaint());
    }

    /**
     * Reads the environment value a key falls back to.
     *
     * @param EnvConstants $key Environment variable name
     * @return int Effective environment value
     */
    private function envInt(EnvConstants $key): int
    {
        return max(0, Hilos::$env?->int($key) ?? 0);
    }
}

/**
 * Settings accessor whose stored values are scripted by the test.
 *
 * Carries the real log catalog, so the keys exist and their defaults are the environment ones;
 * only the persisted layer is replaced. A value that is a Throwable is thrown instead of returned,
 * which is how a failing database read is staged.
 */
final class LogSettingsResolverTestAccessor extends SettingsAccessor
{
    /** @var array<string, mixed> Persisted values by key; a Throwable is thrown on read */
    public static array $values = [];

    /**
     * Returns the scripted value for a key, or the catalog default when none is scripted.
     *
     * @param string $key Setting key
     * @return mixed Scripted persisted value, or the resolved catalog default
     * @throws Throwable When the scripted value is a throwable staging a failing read
     */
    public function effectiveValueFor(string $key): mixed
    {
        $value = self::$values[$key] ?? null;
        if ($value instanceof Throwable) {
            throw $value;
        }

        return $value ?? parent::effectiveValueFor($key);
    }
}

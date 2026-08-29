<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\Log\LogWriteLevelResolver;
use Hilos\Log\LogWriteLevelSubscriber;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the subscriber that turns a settings change back into a write level (HIL-761).
 *
 * This is the whole of "without a restart and without polling": the row is written somewhere in
 * the cluster, the change is announced on the source bus, and the threshold follows. What is
 * locked here is that it follows for the row that carries the level and for nothing else -
 * except an update, whose diff names no key and therefore cannot be dismissed without looking.
 */
final class LogWriteLevelSubscriberTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        LogWriteLevelSubscriberTestAccessor::$values = [];
        Hilos::$setting = new LogWriteLevelSubscriberTestAccessor(LogSettingsCatalog::class);
        LogWriteLevelApplier::reset();
        Logger::setWriteLevel(LogLevel::Info);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;
        LogWriteLevelSubscriberTestAccessor::$values = [];
        LogWriteLevelApplier::reset();
        Logger::setWriteLevel(LogLevel::Info);

        parent::tearDown();
    }

    public function testACreatedWriteLevelRowRaisesTheThreshold(): void
    {
        LogWriteLevelSubscriberTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => LogLevel::Warning->value];

        $this->announce(SourceChange::dbCreated(
            HilosDbContext::settings,
            '7',
            [Setting::key => LogSettingsCatalog::WRITE_LEVEL, Setting::value => LogLevel::Warning->value],
        ));

        $this->assertSame(LogLevel::Warning, Logger::writeLevel());
    }

    /**
     * Editing a setting changes its value alone, so the announced diff names no key: the level is
     * re-read rather than guessed at, and it is the settings that answer what it now is.
     */
    public function testAnUpdateDiffWithoutAKeyStillReachesTheThreshold(): void
    {
        LogWriteLevelSubscriberTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => LogLevel::Error->value];

        $this->announce(SourceChange::dbUpdated(
            HilosDbContext::settings,
            '7',
            [Setting::value => LogLevel::Error->value],
        ));

        $this->assertSame(LogLevel::Error, Logger::writeLevel());
    }

    /**
     * Removing the row is not "no answer" but "the environment answers again".
     */
    public function testADeletedWriteLevelRowHandsTheKeyBackToTheEnvironment(): void
    {
        Logger::setWriteLevel(LogLevel::Error);

        $this->announce(SourceChange::dbDeleted(
            HilosDbContext::settings,
            '7',
            [Setting::key => LogSettingsCatalog::WRITE_LEVEL, Setting::value => LogLevel::Error->value],
        ));

        $this->assertSame(LogWriteLevelResolver::fromEnv(), Logger::writeLevel());
    }

    public function testACreatedRowForAnotherSettingIsIgnored(): void
    {
        LogWriteLevelSubscriberTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => LogLevel::Error->value];

        $this->announce(SourceChange::dbCreated(
            HilosDbContext::settings,
            '9',
            [Setting::key => LogSettingsCatalog::ROTATION_CRON, Setting::value => '0 3 * * *'],
        ));

        $this->assertSame(LogLevel::Info, Logger::writeLevel());
    }

    public function testAChangeInAnotherCollectionIsIgnored(): void
    {
        LogWriteLevelSubscriberTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => LogLevel::Error->value];

        $this->announce(SourceChange::dbUpdated(
            HilosDbContext::sessions,
            '7',
            [Setting::value => LogLevel::Error->value],
        ));

        $this->announce(SourceChange::rtCreated(
            HilosDbContext::settings,
            '7',
            [Setting::key => LogSettingsCatalog::WRITE_LEVEL, Setting::value => LogLevel::Error->value],
        ));

        $this->assertSame(LogLevel::Info, Logger::writeLevel());
    }

    /**
     * Hands one fact to a freshly built subscriber, as the bus would.
     *
     * @param SourceChange $change Fact to announce
     */
    private function announce(SourceChange $change): void
    {
        new LogWriteLevelSubscriber()->onSourceChange($change, SourceChangeProvenance::LocalWrite);
    }
}

/**
 * Settings accessor whose stored values are scripted by the test.
 *
 * Carries the real log catalog, so the keys exist and their defaults are the environment ones;
 * only the persisted layer is replaced. Declared here rather than shared with the resolver test:
 * a helper at the bottom of another test file is not autoloadable from this one.
 */
final class LogWriteLevelSubscriberTestAccessor extends SettingsAccessor
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

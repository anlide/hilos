<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Hilos;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogSettingsResolver;
use Hilos\Log\LogWriteLevelResolver;
use Hilos\Utils\LogLevel;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the settings-over-environment reader of the log write level (HIL-761).
 *
 * Walks the same ladder its neighbour {@see LogSettingsResolver} does - a written
 * setting wins, no setting means the environment, a project without the catalog fragment stays on
 * the environment silently, and an unusable value falls back with one complaint - and adds the one
 * question this reader is asked that the other is not: which road the value actually came by, so
 * the journal line about the change can name it without lying.
 */
final class LogWriteLevelResolverTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        LogWriteLevelTestAccessor::$values = [];
        Hilos::$setting = new LogWriteLevelTestAccessor(LogSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;
        LogWriteLevelTestAccessor::$values = [];

        parent::tearDown();
    }

    public function testAWrittenSettingOverridesTheEnvironment(): void
    {
        LogWriteLevelTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => LogLevel::Warning->value];

        $resolver = new LogWriteLevelResolver();

        $this->assertSame(LogLevel::Warning, $resolver->resolve());
        $this->assertSame(LogWriteLevelResolver::SOURCE_SETTING, $resolver->lastSource());
        $this->assertNull($resolver->takeComplaint());
    }

    /**
     * With no row written the value is still the environment's, because that is what the catalog
     * declares as the key's default - so the level does not change, and the source reads as the
     * settings having answered. The two source names separate "the settings answered" from "the
     * settings could not answer", which is the only distinction a journal line can act on.
     */
    public function testWithNoRowWrittenTheCatalogDefaultCarriesTheEnvironmentValue(): void
    {
        $resolver = new LogWriteLevelResolver();

        $this->assertSame(LogWriteLevelResolver::fromEnv(), $resolver->resolve());
        $this->assertSame(LogWriteLevelResolver::SOURCE_SETTING, $resolver->lastSource());
        $this->assertNull($resolver->takeComplaint());
    }

    /**
     * A project that never folded the log fragment into its catalog is not in trouble: it is the
     * installation configured by environment alone, and it stays that way without a word.
     */
    public function testACatalogWithoutTheKeyStaysOnTheEnvironmentSilently(): void
    {
        Hilos::$setting = new SettingsAccessor(SettingsCatalogStub::class);

        $resolver = new LogWriteLevelResolver();

        $this->assertSame(LogWriteLevelResolver::fromEnv(), $resolver->resolve());
        $this->assertSame(LogWriteLevelResolver::SOURCE_ENV, $resolver->lastSource());
        $this->assertNull($resolver->takeComplaint());
    }

    public function testUninitializedSettingsFallBackToTheEnvironmentAndComplain(): void
    {
        Hilos::$setting = null;

        $resolver = new LogWriteLevelResolver();

        $this->assertSame(LogWriteLevelResolver::fromEnv(), $resolver->resolve());
        $this->assertSame(LogWriteLevelResolver::SOURCE_ENV, $resolver->lastSource());
        $this->assertNotNull($resolver->takeComplaint());
    }

    public function testAStoredValueThatIsNotALevelFallsBackAndComplains(): void
    {
        LogWriteLevelTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => 'TRACE'];

        $resolver = new LogWriteLevelResolver();

        $this->assertSame(LogWriteLevelResolver::fromEnv(), $resolver->resolve());
        $this->assertSame(LogWriteLevelResolver::SOURCE_ENV, $resolver->lastSource());

        $complaint = $resolver->takeComplaint();
        $this->assertNotNull($complaint);
        $this->assertStringContainsString('falling back to env', $complaint);
    }

    public function testAFailingReadFallsBackAndComplains(): void
    {
        LogWriteLevelTestAccessor::$values = [
            LogSettingsCatalog::WRITE_LEVEL => new DatabaseException('settings table is gone'),
        ];

        $resolver = new LogWriteLevelResolver();

        $this->assertSame(LogWriteLevelResolver::fromEnv(), $resolver->resolve());

        $complaint = $resolver->takeComplaint();
        $this->assertNotNull($complaint);
        $this->assertStringContainsString('settings table is gone', $complaint);
    }

    public function testTheSameFaultIsComplainedAboutOnceAndAgainAfterItReturns(): void
    {
        LogWriteLevelTestAccessor::$values = [LogSettingsCatalog::WRITE_LEVEL => 'TRACE'];
        $resolver = new LogWriteLevelResolver();

        $resolver->resolve();
        $this->assertNotNull($resolver->takeComplaint());

        // Same bad value, four more frames: the journal hears nothing more about it.
        for ($frame = 0; $frame < 4; $frame++) {
            $resolver->resolve();
        }
        $this->assertNull($resolver->takeComplaint());

        // Fixed, then broken again: the fault is news once more.
        LogWriteLevelTestAccessor::$values[LogSettingsCatalog::WRITE_LEVEL] = LogLevel::Error->value;
        $this->assertSame(LogLevel::Error, $resolver->resolve());
        $this->assertNull($resolver->takeComplaint());

        LogWriteLevelTestAccessor::$values[LogSettingsCatalog::WRITE_LEVEL] = 'TRACE';
        $resolver->resolve();
        $this->assertNotNull($resolver->takeComplaint());
    }

    public function testTheEnvironmentReaderNeverAnswersWithSomethingThatIsNotALevel(): void
    {
        $this->assertContains(LogWriteLevelResolver::fromEnv(), LogLevel::cases());
    }
}

/**
 * Settings accessor whose stored values are scripted by the test.
 *
 * Carries the real log catalog, so the keys exist and their defaults are the environment ones;
 * only the persisted layer is replaced. A value that is a Throwable is thrown instead of returned,
 * which is how a failing database read is staged.
 */
final class LogWriteLevelTestAccessor extends SettingsAccessor
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

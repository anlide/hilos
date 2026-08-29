<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Database\Settings\Exception\SettingInvalidValueException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogWriteLevelRule;
use Hilos\Utils\LogLevel;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rule guarding what may be written as the log write level (HIL-761).
 *
 * The scale has no "off" step, so an empty value is refused here where the neighbouring schedule
 * rule accepts it; and the refusal an administrator reads has to keep naming the levels that
 * actually exist, which is the one thing a list written out by hand stops doing quietly.
 */
final class LogWriteLevelRuleTest extends TestCase
{
    private ?SettingsAccessor $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSettings = Hilos::$setting;
        Hilos::$setting = new SettingsAccessor(LogSettingsCatalog::class);
    }

    protected function tearDown(): void
    {
        Hilos::$setting = $this->previousSettings;

        parent::tearDown();
    }

    public function testEveryLevelNameIsAccepted(): void
    {
        foreach (LogLevel::cases() as $level) {
            $this->assertNull(LogWriteLevelRule::validate($level->value), "{$level->value} refused");
        }
    }

    /**
     * Empty is refused, unlike in the schedule rule beside it: there is no step of this scale
     * that means "write nothing", so an empty value names nothing at all.
     */
    public function testEmptyAndUnknownNamesAreRefused(): void
    {
        $refusal = LogWriteLevelRule::validate('');

        $this->assertSame('Value must be one of DEBUG, INFO, WARNING, ERROR', $refusal);
        $this->assertSame($refusal, LogWriteLevelRule::validate('debug'));
        $this->assertSame($refusal, LogWriteLevelRule::validate('TRACE'));
        $this->assertSame($refusal, LogWriteLevelRule::validate('WARN'));
        $this->assertSame($refusal, LogWriteLevelRule::validate(' INFO'));
    }

    public function testAnythingThatIsNotAStringIsRefused(): void
    {
        $this->assertNotNull(LogWriteLevelRule::validate(1));
        $this->assertNotNull(LogWriteLevelRule::validate(true));
        $this->assertNotNull(LogWriteLevelRule::validate(null));
        $this->assertNotNull(LogWriteLevelRule::validate(['INFO']));
    }

    /**
     * The refusal text names exactly the levels that exist, in scale order.
     *
     * Written out by hand in the rule and therefore able to drift; a level added to the scale
     * without this line following it would tell an administrator to type one of four names when
     * five are accepted.
     */
    public function testTheRefusalNamesEveryLevelThatExists(): void
    {
        $names = implode(', ', array_map(static fn(LogLevel $level): string => $level->value, LogLevel::cases()));

        $this->assertSame("Value must be one of {$names}", LogWriteLevelRule::validate('not-a-level'));
    }

    public function testTheWritePathRefusesAnUnknownNameWithTheTextOfTheRule(): void
    {
        $this->expectException(SettingInvalidValueException::class);
        $this->expectExceptionMessage((string)LogWriteLevelRule::validate('TRACE'));

        SettingValueRules::assertValid(LogSettingsCatalog::WRITE_LEVEL, 'TRACE');
    }

    /**
     * The default the catalog publishes has to pass the key's own rule, or the settings screen
     * would show a value an administrator could not save back unchanged.
     */
    public function testTheCatalogDefaultPassesItsOwnRule(): void
    {
        $entry = LogSettingsCatalog::getCatalog()[LogSettingsCatalog::WRITE_LEVEL];

        $this->assertNull(LogWriteLevelRule::validate(
            $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE],
        ));
    }
}

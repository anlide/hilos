<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\Log\LogIndexPushIntervalRule;
use Hilos\Log\LogSettingsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the floor under the log-index push interval (HIL-754).
 *
 * What makes this rule its own rather than the non-negative one its five neighbours share is that
 * zero is not "off" here — nothing turns the reporting off — so it has to be refused like any
 * other number below the floor. These tests lock that, and that the settings write path refuses a
 * bad interval with the rule's own words.
 */
final class LogIndexPushIntervalRuleTest extends TestCase
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

    public function testTheMinimumAndAnythingAboveItIsAccepted(): void
    {
        $this->assertNull(LogIndexPushIntervalRule::validate(LogIndexPushIntervalRule::MINIMUM_MS));
        $this->assertNull(LogIndexPushIntervalRule::validate(5000));
        $this->assertNull(LogIndexPushIntervalRule::validate('100'));
        $this->assertNull(LogIndexPushIntervalRule::validate('60000'));
    }

    /**
     * Zero is refused like any other too-small number, and that is the whole reason for this rule:
     * under the neighbouring one it would have read as "send with no limit at all".
     */
    public function testZeroAndAnythingBelowTheMinimumIsRefused(): void
    {
        $refusal = LogIndexPushIntervalRule::validate(0);

        $this->assertSame('Value must be an integer of 100 or more', $refusal);
        $this->assertSame($refusal, LogIndexPushIntervalRule::validate(99));
        $this->assertSame($refusal, LogIndexPushIntervalRule::validate('99'));
        $this->assertSame($refusal, LogIndexPushIntervalRule::validate('0'));
        $this->assertSame($refusal, LogIndexPushIntervalRule::validate(-1));
    }

    public function testAnythingThatIsNotAWholeNumberIsRefused(): void
    {
        $this->assertNotNull(LogIndexPushIntervalRule::validate(100.5));
        $this->assertNotNull(LogIndexPushIntervalRule::validate('5 seconds'));
        $this->assertNotNull(LogIndexPushIntervalRule::validate(''));
        $this->assertNotNull(LogIndexPushIntervalRule::validate(true));
        $this->assertNotNull(LogIndexPushIntervalRule::validate(null));
    }

    public function testTheWritePathRefusesATooSmallIntervalWithTheTextOfTheRule(): void
    {
        $this->expectException(SettingValueRefusedException::class);
        $this->expectExceptionMessage((string)LogIndexPushIntervalRule::validate(99));

        SettingValueRules::assertValid(LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS, 99);
    }

    /**
     * The default the catalog publishes has to pass the key's own rule, or the settings screen
     * would show a value an administrator could not save back unchanged.
     */
    public function testTheCatalogDefaultPassesItsOwnRule(): void
    {
        $entry = LogSettingsCatalog::getCatalog()[LogSettingsCatalog::INDEX_PUSH_INTERVAL_MS];

        $this->assertNull(LogIndexPushIntervalRule::validate(
            $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE],
        ));
    }
}

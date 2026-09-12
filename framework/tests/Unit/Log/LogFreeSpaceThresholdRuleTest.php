<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Database\Settings\Exception\SettingValueRefusedException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\SettingValueRules;
use Hilos\Hilos;
use Hilos\Log\LogFreeSpaceThresholdRule;
use Hilos\Log\LogSettingsCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ceiling over the log free-space threshold (HIL-869).
 *
 * What makes this rule its own rather than the non-negative one its neighbours share is the
 * ceiling: a reserve larger than the volume is one the overview could only ever report as already
 * spent. Zero, by contrast, is meaningful here and has to be accepted — it is the installation
 * counting the days to a full disk. These tests lock both ends, and that the settings write path
 * refuses an impossible share with the rule's own words.
 */
final class LogFreeSpaceThresholdRuleTest extends TestCase
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

    public function testEverySharePlacedOnTheVolumeIsAccepted(): void
    {
        $this->assertNull(LogFreeSpaceThresholdRule::validate(20));
        $this->assertNull(LogFreeSpaceThresholdRule::validate(LogFreeSpaceThresholdRule::MAXIMUM_PERCENT));
        $this->assertNull(LogFreeSpaceThresholdRule::validate('20'));
        $this->assertNull(LogFreeSpaceThresholdRule::validate('99'));
    }

    /**
     * Zero is accepted here although the neighbouring numeric keys read it as "axis off": a disk
     * always has a floor, so the only thing zero can mean is "count the days to a full one".
     */
    public function testZeroIsAcceptedBecauseItCountsTheDaysToAFullDisk(): void
    {
        $this->assertNull(LogFreeSpaceThresholdRule::validate(0));
        $this->assertNull(LogFreeSpaceThresholdRule::validate('0'));
    }

    public function testAShareLargerThanTheVolumeIsRefused(): void
    {
        $refusal = LogFreeSpaceThresholdRule::validate(100);

        $this->assertSame('Value must be an integer from 0 to 99', $refusal);
        $this->assertSame($refusal, LogFreeSpaceThresholdRule::validate(150));
        $this->assertSame($refusal, LogFreeSpaceThresholdRule::validate('150'));
        $this->assertSame($refusal, LogFreeSpaceThresholdRule::validate(-1));
    }

    public function testAnythingThatIsNotAWholeNumberIsRefused(): void
    {
        $this->assertNotNull(LogFreeSpaceThresholdRule::validate(20.5));
        $this->assertNotNull(LogFreeSpaceThresholdRule::validate('20 percent'));
        $this->assertNotNull(LogFreeSpaceThresholdRule::validate(''));
        $this->assertNotNull(LogFreeSpaceThresholdRule::validate(true));
        $this->assertNotNull(LogFreeSpaceThresholdRule::validate(null));
    }

    public function testTheWritePathRefusesAnImpossibleShareWithTheTextOfTheRule(): void
    {
        $this->expectException(SettingValueRefusedException::class);
        $this->expectExceptionMessage((string)LogFreeSpaceThresholdRule::validate(150));

        SettingValueRules::assertValid(LogSettingsCatalog::FREE_SPACE_THRESHOLD_PERCENT, 150);
    }

    /**
     * The default the catalog publishes has to pass the key's own rule, or the settings screen
     * would show a value an administrator could not save back unchanged.
     */
    public function testTheCatalogDefaultPassesItsOwnRule(): void
    {
        $entry = LogSettingsCatalog::getCatalog()[LogSettingsCatalog::FREE_SPACE_THRESHOLD_PERCENT];

        $this->assertNull(LogFreeSpaceThresholdRule::validate(
            $entry[SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE],
        ));
    }
}

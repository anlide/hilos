<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Tables\Settings\HilosSettingTableRow;
use Hilos\Tables\Settings\HilosSettingsTable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework settings table self-snapshot serialization.
 */
final class HilosSettingsTableTest extends TestCase
{
    public function testBrowserRowDropsIdAndWrapsSlotUnderSettingsSource(): void
    {
        $row = new HilosSettingTableRow(
            id: 7,
            key: 'feature_flag',
            type: 'string',
            value: 'on',
            overrideValue: 'on',
            defaultValue: 'off',
            defaultReferenceKey: null,
            valueSource: HilosSettingTableRow::VALUE_SOURCE_OVERRIDE,
            persisted: true,
        );

        $browserRow = new HilosSettingsTable()->browserRow($row);

        $this->assertSame('feature_flag', $browserRow[BrowserPageSignalData::rowKey]);

        $slot = $browserRow[BrowserPageSignalData::sources][HilosDbContext::settings];
        $this->assertArrayNotHasKey(HilosSettingTableRow::id, $slot);
        $this->assertSame('feature_flag', $slot[HilosSettingTableRow::key]);
        $this->assertSame('on', $slot[HilosSettingTableRow::value]);
        $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_OVERRIDE, $slot[HilosSettingTableRow::valueSource]);
        // The frontend reads persisted-ness from this flag, since the id is stripped
        // above and value_source cannot tell a stored NULL from an absent row.
        $this->assertTrue($slot[HilosSettingTableRow::persisted]);
    }
}

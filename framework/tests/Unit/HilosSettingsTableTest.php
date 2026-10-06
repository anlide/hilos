<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
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
        );

        $browserRow = new HilosSettingsTable()->browserRow($row);

        $this->assertSame('feature_flag', $browserRow[BrowserPageSignalData::rowKey]);

        $slot = $browserRow[BrowserPageSignalData::sources][HilosDbContext::settings];
        $this->assertArrayNotHasKey(HilosSettingTableRow::id, $slot);
        $this->assertSame('feature_flag', $slot[HilosSettingTableRow::key]);
        $this->assertSame('on', $slot[HilosSettingTableRow::value]);
        $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_OVERRIDE, $slot[HilosSettingTableRow::valueSource]);
        // Whether a DB row backs the key is not on the wire: the screen asks overrideValue
        // whether the key carries a value of its own.
        $this->assertSame('on', $slot[HilosSettingTableRow::overrideValue]);
    }

    public function testViewerValueDefaultAndReferenceFollowTheRowKeyButCannotBeSearchedOrSorted(): void
    {
        $table = new HilosSettingsTable();
        $fields = $table->wireFields();
        $row = new HilosSettingTableRow(
            id: null,
            key: 'open',
            type: 'string',
            value: 'live',
            overrideValue: 'live',
            defaultValue: 'fallback',
            defaultReferenceKey: 'parent',
            valueSource: HilosSettingTableRow::VALUE_SOURCE_OVERRIDE,
        );
        $columnShown = static fn(string $collection, string $field): bool => true;
        $settingShown = static fn(string $key): bool => $key === 'open';

        $visible = ViewerFields::hide($row->toArray(), $fields, $columnShown, $settingShown);
        foreach ([
            HilosSettingTableRow::value => 'live',
            HilosSettingTableRow::overrideValue => 'live',
            HilosSettingTableRow::defaultValue => 'fallback',
            HilosSettingTableRow::defaultReferenceKey => 'parent',
        ] as $name => $expected) {
            $this->assertSame($expected, $visible[$name]);
        }

        $row->key = 'orphan';
        $hidden = ViewerFields::hide($row->toArray(), $fields, $columnShown, $settingShown);
        foreach ([
            HilosSettingTableRow::value,
            HilosSettingTableRow::overrideValue,
            HilosSettingTableRow::defaultValue,
            HilosSettingTableRow::defaultReferenceKey,
        ] as $name) {
            $this->assertSame(HiddenValue::mark(), $hidden[$name]);
        }
        $this->assertSame(
            [HilosSettingTableRow::key, HilosSettingTableRow::type, HilosSettingTableRow::valueSource],
            ViewerFields::shownNames($fields, $columnShown),
        );
    }
}

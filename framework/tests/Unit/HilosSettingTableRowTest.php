<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Tables\Settings\HilosSettingTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework settings table row payload.
 */
final class HilosSettingTableRowTest extends TestCase
{
    public function testRowKeyIsSettingKey(): void
    {
        $this->assertSame('chat_bot_model', $this->overrideRow()->getRowKey());
    }

    public function testToArrayExposesAllSettingFields(): void
    {
        $this->assertSame(
            [
                HilosSettingTableRow::id => 7,
                HilosSettingTableRow::key => 'chat_bot_model',
                HilosSettingTableRow::type => 'string',
                HilosSettingTableRow::value => 'qwen2.5:3b',
                HilosSettingTableRow::overrideValue => 'qwen2.5:3b',
                HilosSettingTableRow::defaultValue => 'llama3',
                HilosSettingTableRow::defaultReferenceKey => 'default_bot_model',
                HilosSettingTableRow::valueSource => HilosSettingTableRow::VALUE_SOURCE_OVERRIDE,
            ],
            $this->overrideRow()->toArray(),
        );
    }

    public function testFromArrayRoundTripsToArray(): void
    {
        $payload = $this->overrideRow()->toArray();

        $this->assertSame($payload, HilosSettingTableRow::fromArray($payload)->toArray());
    }

    public function testFromArrayDefaultsMissingFieldsToOrphanRow(): void
    {
        $row = HilosSettingTableRow::fromArray([HilosSettingTableRow::key => 'orphan_key']);

        $this->assertNull($row->id);
        $this->assertSame('orphan_key', $row->key);
        $this->assertNull($row->overrideValue);
        $this->assertSame(HilosSettingTableRow::VALUE_SOURCE_ORPHAN, $row->valueSource);
    }

    /**
     * Builds an overridden cataloged settings row for assertions.
     *
     * @return HilosSettingTableRow Settings table row with an override value
     */
    private function overrideRow(): HilosSettingTableRow
    {
        return new HilosSettingTableRow(
            id: 7,
            key: 'chat_bot_model',
            type: 'string',
            value: 'qwen2.5:3b',
            overrideValue: 'qwen2.5:3b',
            defaultValue: 'llama3',
            defaultReferenceKey: 'default_bot_model',
            valueSource: HilosSettingTableRow::VALUE_SOURCE_OVERRIDE,
        );
    }
}

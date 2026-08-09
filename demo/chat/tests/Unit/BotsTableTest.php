<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\Bot\BotTableRow;
use Demo\Chat\Tables\Bot\BotsTable;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the bots table viewport serialization.
 */
final class BotsTableTest extends TestCase
{
    public function testBrowserRowSplitsIntoBotAndStatusSlots(): void
    {
        $table = new BotsTable();
        $row = new BotTableRow(
            id: 3,
            name: 'Aria',
            description: 'A helpful bot',
            style: 'concise',
            topics: 'tables',
            personality: 'precise',
            active: true,
            reactionDelayMin: 1,
            reactionDelayMax: 5,
            reactionChance: 50,
            topicMatchRequired: false,
            cooldownAfterMessage: 10,
            priority: 2,
            status: StateBotAgentStatus::STATUS_JOINED,
        );

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 3,
                BrowserPageSignalData::sources => [
                    ChatDbContext::bots => [
                        BotTableRow::id => 3,
                        BotTableRow::name => 'Aria',
                        BotTableRow::description => 'A helpful bot',
                        BotTableRow::style => 'concise',
                        BotTableRow::topics => 'tables',
                        BotTableRow::personality => 'precise',
                        BotTableRow::active => true,
                        BotTableRow::reactionDelayMin => 1,
                        BotTableRow::reactionDelayMax => 5,
                        BotTableRow::reactionChance => 50,
                        BotTableRow::topicMatchRequired => false,
                        BotTableRow::cooldownAfterMessage => 10,
                        BotTableRow::priority => 2,
                    ],
                    ChatRtContext::botAgentStatuses => [
                        StateBotAgentStatus::status => StateBotAgentStatus::STATUS_JOINED,
                    ],
                ],
            ],
            $table->browserRow($row),
        );
    }

    public function testBrowserRowRefusesAKeylessRowInsteadOfSendingAnEmptyKey(): void
    {
        $table = new BotsTable();
        $keyless = new class extends AbstractTableRow {
            /**
             * @return string|int|null Row key, absent because this row is a placeholder
             */
            public function getRowKey(): string|int|null
            {
                return null;
            }

            /**
             * @return array<string, mixed> Row payload
             */
            public function toArray(): array
            {
                return [];
            }

            /**
             * @param array<string, mixed> $data Source data
             * @return static Row instance
             */
            public static function fromArray(array $data): static
            {
                return new static();
            }
        };

        $this->expectException(TableRowKeyMissingException::class);
        $table->browserRow($keyless);
    }
}

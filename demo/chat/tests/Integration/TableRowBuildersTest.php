<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\BotTableRow;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for table-local row builders.
 */
final class TableRowBuildersTest extends IntegrationTestCase
{
    public function testBotTableBuilderProjectsDbBackedRows(): void
    {
        $mutation = Hilos::$table->bots->actions->create(new BotCreateActionDTO(
            name: 'Builder Bot ' . RandomHelper::hex(4),
            description: 'DB-backed row builder test',
            style: 'concise',
            topics: 'tables',
            personality: 'precise',
            active: true,
        ));

        $this->assertSame(TableMutationType::Create, $mutation->type);
        $this->assertInstanceOf(BotTableRow::class, $mutation->row);

        $mutationRow = $mutation->row->toArray();
        $this->assertSame('DB-backed row builder test', $mutationRow[BotTableRow::description]);
        $this->assertSame('concise', $mutationRow[BotTableRow::style]);
        $this->assertSame('tables', $mutationRow[BotTableRow::topics]);
        $this->assertSame('precise', $mutationRow[BotTableRow::personality]);
        $this->assertTrue($mutationRow[BotTableRow::active]);

        $snapshot = Hilos::$table->bots->getFullSnapshot()->toArray();
        $snapshotRow = $this->findRowByBotId($snapshot[TableConstants::RESULT_KEY_ROWS], (int) $mutation->rowKey);

        $this->assertSame($mutationRow, $snapshotRow);
    }

    /**
     * Finds a table row by bot id.
     *
     * @param list<array<string, mixed>> $rows Table rows
     * @param int $botId Bot id
     * @return array<string, mixed> Matching row
     */
    private function findRowByBotId(array $rows, int $botId): array
    {
        foreach ($rows as $row) {
            if (($row[ObjectBot::id] ?? null) === $botId) {
                return $row;
            }
        }

        self::fail("Bot row #{$botId} not found");
    }
}

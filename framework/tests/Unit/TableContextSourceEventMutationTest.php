<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Projection\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\GenericTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for building table mutations from routed source events.
 */
final class TableContextSourceEventMutationTest extends TestCase
{
    public function testBuildsMutationSignalsOnlyForReactingTables(): void
    {
        $context = new class extends TableContext {
            public function configure(): void
            {
                $this->register('reacting', new class extends TableDefinition {
                    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
                    {
                        if ($change->sourceKey !== 'users') {
                            return null;
                        }

                        return new TableRowMutationDTO(
                            $change->mutationType,
                            $change->sourceId,
                            GenericTableRow::fromArray(['id' => $change->sourceId]),
                        );
                    }

                    protected function query(TableQueryDTO $query): TableSnapshotDTO
                    {
                        return new TableSnapshotDTO();
                    }
                });

                $this->register('silent', new class extends TableDefinition {
                    protected function query(TableQueryDTO $query): TableSnapshotDTO
                    {
                        return new TableSnapshotDTO();
                    }
                });
            }
        };
        $context->configure();

        $signals = $context->buildMutationSignalsForSourceEvent(
            SourceChange::dbUpdated('users', '5', []),
            ['reacting', 'silent', 'missing'],
        );

        $this->assertCount(1, $signals);
        foreach ($signals as $signal) {
            $this->assertSame('reacting', $signal->tableKey);
            $this->assertSame('5', $signal->mutation->rowKey);
        }
    }
}

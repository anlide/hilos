<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\GenericTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for table mutation row-key wire contract.
 */
final class TableRowMutationDTOTest extends TestCase
{
    public function testToArrayUsesRowKeyWireField(): void
    {
        $mutation = new TableRowMutationDTO(
            TableMutationType::Update,
            'chat.title',
            GenericTableRow::fromArray(['key' => 'chat.title', 'value' => 'Hilos']),
        );

        $this->assertSame([
            'type' => 'update',
            'rowKey' => 'chat.title',
            'row' => ['key' => 'chat.title', 'value' => 'Hilos'],
        ], $mutation->toArray());
    }

    public function testFromArrayReadsRowKeyWireField(): void
    {
        $mutation = TableRowMutationDTO::fromArray([
            'type' => 'delete',
            'rowKey' => 'chat.title',
        ]);

        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame('chat.title', $mutation->rowKey);
        $this->assertNull($mutation->row);
    }

    public function testToArrayDoesNotIncludeRoutingMetadata(): void
    {
        $mutation = new TableRowMutationDTO(
            TableMutationType::Create,
            42,
            GenericTableRow::fromArray(['id' => 42, 'name' => 'Ada']),
        );

        $this->assertArrayNotHasKey('acceptKey', $mutation->toArray());
    }

    public function testGenericRowCanUseStringKeyWithoutDbId(): void
    {
        $row = GenericTableRow::fromArray(['key' => 'chat.title']);

        $this->assertSame('chat.title', $row->getRowKey());
    }
}

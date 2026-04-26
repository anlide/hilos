<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\GenericTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for table mutation row-key wire contract.
 */
final class TableMutationEntryTest extends TestCase
{
    public function testToArrayUsesRowKeyWireField(): void
    {
        $entry = new TableMutationEntry(
            TableMutationType::Updated,
            'chat.title',
            GenericTableRow::fromArray(['key' => 'chat.title', 'value' => 'Hilos']),
        );

        $this->assertSame([
            'type' => 'updated',
            'rowKey' => 'chat.title',
            'row' => ['key' => 'chat.title', 'value' => 'Hilos'],
        ], $entry->toArray());
    }

    public function testFromArrayReadsRowKeyWireField(): void
    {
        $entry = TableMutationEntry::fromArray([
            'type' => 'deleted',
            'rowKey' => 'chat.title',
        ]);

        $this->assertSame(TableMutationType::Deleted, $entry->type);
        $this->assertSame('chat.title', $entry->rowKey);
        $this->assertNull($entry->row);
    }

    public function testGenericRowCanUseStringKeyWithoutDbId(): void
    {
        $row = GenericTableRow::fromArray(['key' => 'chat.title']);

        $this->assertSame('chat.title', $row->getRowKey());
    }
}

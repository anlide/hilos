<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\DTO\EmitDbChangeSignalData;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for table source-event transport.
 */
final class TableSourceEventDTOTest extends TestCase
{
    public function testRoundtripPreservesSourceEventFields(): void
    {
        $event = new TableSourceEventDTO(
            sourceKey: 'users',
            sourceRowKey: 42,
            mutationType: TableMutationType::Update,
        );

        $restored = TableSourceEventDTO::fromArray($event->toArray());

        $this->assertSame('users', $restored->sourceKey);
        $this->assertSame(42, $restored->sourceRowKey);
        $this->assertSame(TableMutationType::Update, $restored->mutationType);
    }

    public function testEmitDbChangeRoundtripPreservesSourceEventAndRoutingMetadata(): void
    {
        $payload = new EmitDbChangeSignalData(
            sourceEvent: new TableSourceEventDTO('users', '7', TableMutationType::Update),
            excludeAcceptKey: 'client-a',
            actorUserId: 3,
        );

        $restored = EmitDbChangeSignalData::fromArray($payload->toArray());

        $this->assertSame('users', $restored->sourceEvent->sourceKey);
        $this->assertSame('7', $restored->sourceEvent->sourceRowKey);
        $this->assertSame(TableMutationType::Update, $restored->sourceEvent->mutationType);
        $this->assertSame('client-a', $restored->excludeAcceptKey);
        $this->assertSame(3, $restored->actorUserId);
        $this->assertArrayNotHasKey('tableKey', $restored->toArray());
        $this->assertArrayNotHasKey('mutation', $restored->toArray());
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\TableConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the client-to-server table viewport signal DTO.
 */
final class WebSocketTableViewportSignalDTOTest extends TestCase
{
    public function testRoundTripPreservesAllFields(): void
    {
        $dto = new WebSocketTableViewportSignalDTO(
            acceptKey: 'ak',
            page: 'hilos_settings',
            tableKey: 'settings',
            filter: ['search' => 'theme'],
            sortField: 'key',
            sortDirection: TableConstants::ORDER_DESC,
            offset: 10,
            limit: 10,
        );

        $restored = WebSocketTableViewportSignalDTO::fromArray($dto->toArray());

        $this->assertSame('ak', $restored->acceptKey);
        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(['search' => 'theme'], $restored->filter);
        $this->assertSame('key', $restored->sortField);
        $this->assertSame(TableConstants::ORDER_DESC, $restored->sortDirection);
        $this->assertSame(10, $restored->offset);
        $this->assertSame(10, $restored->limit);
    }

    public function testSortRidesAsNestedFieldDirection(): void
    {
        $array = (new WebSocketTableViewportSignalDTO(
            acceptKey: 'ak',
            tableKey: 't',
            sortField: 'name',
            sortDirection: TableConstants::ORDER_ASC,
        ))->toArray();

        $this->assertSame(
            ['field' => 'name', 'direction' => TableConstants::ORDER_ASC],
            $array[WebSocketTableViewportSignalDTO::SORT],
        );
    }

    public function testNoSortKeyWithoutASortField(): void
    {
        $array = (new WebSocketTableViewportSignalDTO(acceptKey: 'ak', tableKey: 't'))->toArray();

        $this->assertArrayNotHasKey(WebSocketTableViewportSignalDTO::SORT, $array);
    }

    public function testFromArrayDefaultsForMissingFields(): void
    {
        $dto = WebSocketTableViewportSignalDTO::fromArray(['acceptKey' => 'ak']);

        $this->assertSame('ak', $dto->acceptKey);
        $this->assertSame('', $dto->page);
        $this->assertSame('', $dto->tableKey);
        $this->assertSame([], $dto->filter);
        $this->assertSame('', $dto->sortField);
        $this->assertSame(0, $dto->offset);
        $this->assertSame(TableConstants::NO_LIMIT, $dto->limit);
    }

    public function testGetAcceptKey(): void
    {
        $this->assertSame('ak', (new WebSocketTableViewportSignalDTO(acceptKey: 'ak'))->getAcceptKey());
    }
}

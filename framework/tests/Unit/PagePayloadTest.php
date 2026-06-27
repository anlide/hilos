<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PagePayload;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PagePayload section serialization and emptiness.
 */
final class PagePayloadTest extends TestCase
{
    public function testEmptyPayloadReportsEmptyAndSerializesToEmptyArray(): void
    {
        $payload = new PagePayload();

        $this->assertTrue($payload->isEmpty());
        $this->assertSame([], $payload->toArray());
    }

    public function testEntitiesOnlyPayloadOmitsDataSection(): void
    {
        $payload = new PagePayload(entities: ['currentUser' => ['id' => 7, 'name' => 'Ada']]);

        $this->assertFalse($payload->isEmpty());
        $this->assertSame(
            [PagePayload::entities => ['currentUser' => ['id' => 7, 'name' => 'Ada']]],
            $payload->toArray(),
        );
    }

    public function testDataOnlyPayloadOmitsEntitiesSection(): void
    {
        $payload = new PagePayload(data: ['title' => 'Lobby']);

        $this->assertFalse($payload->isEmpty());
        $this->assertSame(
            [PagePayload::data => ['title' => 'Lobby']],
            $payload->toArray(),
        );
    }

    public function testBothSectionsSerializeWhenPresent(): void
    {
        $payload = new PagePayload(
            entities: ['authors' => [['id' => 1], ['id' => 2]]],
            data: ['count' => 2],
        );

        $this->assertSame(
            [
                PagePayload::entities => ['authors' => [['id' => 1], ['id' => 2]]],
                PagePayload::data => ['count' => 2],
            ],
            $payload->toArray(),
        );
    }
}

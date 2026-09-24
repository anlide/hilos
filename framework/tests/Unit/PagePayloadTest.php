<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Table\DTO\TableWindowRefusedSignalData;
use Hilos\Core\Table\TableWindowRefusalCode;
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

    public function testRefusedWindowsOnlyPayloadIsNotEmptyAndSerializesThatSection(): void
    {
        $refusal = ['settings' => [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR]];
        $payload = new PagePayload(refusedWindows: $refusal);

        $this->assertFalse($payload->isEmpty());
        $this->assertSame([PagePayload::refusedWindows => $refusal], $payload->toArray());
    }

    public function testEmptyRefusedWindowsAreOmitted(): void
    {
        $payload = new PagePayload(windows: ['settings' => [PagePayload::rows => []]]);

        $this->assertArrayNotHasKey(PagePayload::refusedWindows, $payload->toArray());
    }

    public function testPageResponseFromArrayReadsRefusedWindowsBack(): void
    {
        $refusal = ['settings' => [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR]];
        $restored = PageResponseSignalData::fromArray(
            (new PageResponseSignalData('main', new PagePayload(refusedWindows: $refusal)))->toArray(),
        );

        $this->assertSame($refusal, $restored->payload->refusedWindows);
    }
}

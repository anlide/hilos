<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageResponseSignalData wire shape and round-trip.
 */
final class PageResponseSignalDataTest extends TestCase
{
    public function testToArrayWrapsPageKeyAndPayload(): void
    {
        $data = new PageResponseSignalData(
            'main',
            new PagePayload(
                entities: ['currentUser' => ['id' => 7, 'name' => 'Ada']],
                data: ['title' => 'Lobby'],
            ),
        );

        $this->assertSame(
            [
                PageResponseSignalData::page => 'main',
                PageResponseSignalData::payload => [
                    PagePayload::entities => ['currentUser' => ['id' => 7, 'name' => 'Ada']],
                    PagePayload::data => ['title' => 'Lobby'],
                ],
            ],
            $data->toArray(),
        );
    }

    public function testToArrayOmitsEmptyPayloadSections(): void
    {
        $data = new PageResponseSignalData('main', new PagePayload(data: ['title' => 'Lobby']));

        $this->assertSame(
            [
                PageResponseSignalData::page => 'main',
                PageResponseSignalData::payload => [PagePayload::data => ['title' => 'Lobby']],
            ],
            $data->toArray(),
        );
    }

    public function testFromArrayRestoresPageKeyAndPayloadSections(): void
    {
        $restored = PageResponseSignalData::fromArray([
            PageResponseSignalData::page => 'admin_users',
            PageResponseSignalData::payload => [
                PagePayload::entities => ['author' => ['id' => 3, 'name' => 'Lin']],
                PagePayload::data => ['count' => 1],
            ],
        ]);

        $this->assertSame('admin_users', $restored->pageKey);
        $this->assertSame(['author' => ['id' => 3, 'name' => 'Lin']], $restored->payload->entities);
        $this->assertSame(['count' => 1], $restored->payload->data);
    }

    public function testFromArrayDefaultsMissingSectionsToEmpty(): void
    {
        $restored = PageResponseSignalData::fromArray([PageResponseSignalData::page => 'main']);

        $this->assertSame('main', $restored->pageKey);
        $this->assertTrue($restored->payload->isEmpty());
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Table\DTO\TableWindowRefusedSignalData;
use Hilos\Core\Table\TableWindowRefusalCode;
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

    public function testEverySectionSurvivesTheHopThatRebuildsTheFrame(): void
    {
        $sections = [
            PagePayload::entities => ['author' => ['id' => 3]],
            PagePayload::data => ['count' => 1],
            PagePayload::lists => ['feed' => [PagePayload::items => []]],
            PagePayload::tables => ['bots' => [PagePayload::rows => []]],
            PagePayload::windows => ['settings' => [PagePayload::rows => []]],
            PagePayload::refusedWindows => ['logs' => [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR]],
        ];

        $restored = PageResponseSignalData::fromArray(
            new PageResponseSignalData('admin_users', new PagePayload(
                entities: $sections[PagePayload::entities],
                data: $sections[PagePayload::data],
                lists: $sections[PagePayload::lists],
                tables: $sections[PagePayload::tables],
                windows: $sections[PagePayload::windows],
                refusedWindows: $sections[PagePayload::refusedWindows],
            ))->toArray(),
        );

        // This is the door the whole answer walks through on the worker-to-master hop, so a
        // section this reader does not name is a section the client never sees. The windows
        // section was lost exactly that way, and a page whose only section it was arrived as
        // a bare page key with no payload at all.
        $this->assertSame($sections, $restored->payload->toArray());
    }

    public function testFromArrayDefaultsMissingSectionsToEmpty(): void
    {
        $restored = PageResponseSignalData::fromArray([PageResponseSignalData::page => 'main']);

        $this->assertSame('main', $restored->pageKey);
        $this->assertTrue($restored->payload->isEmpty());
    }
}

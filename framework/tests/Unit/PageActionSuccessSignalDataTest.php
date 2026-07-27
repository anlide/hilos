<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use PHPUnit\Framework\TestCase;

final class PageActionSuccessSignalDataTest extends TestCase
{
    public function testStoresActionAndRequestId(): void
    {
        $data = new PageActionSuccessSignalData('moderator_piece_update', 'req-3');

        $this->assertSame('moderator_piece_update', $data->action);
        $this->assertSame('req-3', $data->requestId);
        $this->assertNull($data->message);
        $this->assertSame([
            'action' => 'moderator_piece_update',
            'requestId' => 'req-3',
        ], $data->toArray());
    }

    public function testOmitsMessageWhenAbsent(): void
    {
        $data = new PageActionSuccessSignalData('moderator_piece_update', 'req-3');

        $this->assertArrayNotHasKey('message', $data->toArray());
    }

    public function testCarriesBackendSuccessMessageWhenSet(): void
    {
        $data = new PageActionSuccessSignalData('moderator_piece_update', 'req-3', 'Piece approved.');

        $this->assertSame('Piece approved.', $data->message);
        $this->assertSame([
            'action' => 'moderator_piece_update',
            'requestId' => 'req-3',
            'message' => 'Piece approved.',
        ], $data->toArray());
    }

    public function testRoundtripPreservesTheSuccessMessage(): void
    {
        $original = new PageActionSuccessSignalData('moderator_piece_update', 'req-3', 'Piece approved.');
        $restored = PageActionSuccessSignalData::fromArray($original->toArray());

        $this->assertSame('Piece approved.', $restored->message);
    }

    public function testRoundtripPreservesSuccessMarkerAndRequestId(): void
    {
        $original = new PageActionSuccessSignalData('moderator_piece_update', 'req-3');
        $restored = PageActionSuccessSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $restored);
        $this->assertSame('success', $restored->getEnvelopeOutcome());
        $this->assertSame('req-3', $restored->getEnvelopeRequestId());
        $this->assertNull($restored->getEnvelopeTime());
    }
}

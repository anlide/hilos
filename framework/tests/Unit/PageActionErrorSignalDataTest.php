<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\WebSocketEnvelopeAware;
use PHPUnit\Framework\TestCase;

final class PageActionErrorSignalDataTest extends TestCase
{
    public function testStoresActionAndReason(): void
    {
        $data = new PageActionErrorSignalData('message', 'Message cannot be empty');

        $this->assertSame('message', $data->action);
        $this->assertSame('Message cannot be empty', $data->reason);
        $this->assertSame([
            'action' => 'message',
            'reason' => 'Message cannot be empty',
        ], $data->toArray());
    }

    public function testRoundtripPreservesEnvelopeMarker(): void
    {
        $original = new PageActionErrorSignalData('message', 'Message cannot be empty');
        $restored = PageActionErrorSignalData::fromArray($original->toArray());

        $this->assertInstanceOf(WebSocketEnvelopeAware::class, $restored);
        $this->assertSame('fail', $restored->getEnvelopeOutcome());
        $this->assertNull($restored->getEnvelopeRequestId());
        $this->assertNull($restored->getEnvelopeTime());
    }

    public function testUntrackedErrorOmitsRequestId(): void
    {
        $data = new PageActionErrorSignalData('message', 'boom');

        $this->assertNull($data->getEnvelopeRequestId());
        $this->assertArrayNotHasKey('requestId', $data->toArray());
    }

    public function testTrackedErrorCarriesRequestIdThroughRoundtrip(): void
    {
        $original = new PageActionErrorSignalData('message', 'boom', 'req-7');

        $this->assertSame([
            'action' => 'message',
            'reason' => 'boom',
            'requestId' => 'req-7',
        ], $original->toArray());

        $restored = PageActionErrorSignalData::fromArray($original->toArray());
        $this->assertSame('req-7', $restored->getEnvelopeRequestId());
        $this->assertSame('fail', $restored->getEnvelopeOutcome());
    }

    public function testUnclassifiedErrorOmitsErrorCode(): void
    {
        $this->assertArrayNotHasKey(
            PageActionErrorSignalData::errorCode,
            (new PageActionErrorSignalData('message', 'boom'))->toArray(),
        );
    }

    public function testErrorCodeCarriesThroughRoundtrip(): void
    {
        $original = new PageActionErrorSignalData('message', 'Authentication required', 'req-7', 'unauthorized');

        $this->assertSame([
            'action' => 'message',
            'reason' => 'Authentication required',
            'requestId' => 'req-7',
            'errorCode' => 'unauthorized',
        ], $original->toArray());

        $this->assertSame('unauthorized', PageActionErrorSignalData::fromArray($original->toArray())->errorCode);
    }
}

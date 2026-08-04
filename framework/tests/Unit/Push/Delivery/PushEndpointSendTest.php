<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push\Delivery;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Push\Delivery\PushEndpointSend;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests one device endpoint's non-blocking web-push send classification (HIL-199).
 *
 * The send wraps a single endpoint's {@see AsyncHttpClient} and latches a classified
 * outcome the moment it settles: a 2xx delivers, a 404/410 marks the subscription
 * {@see PushEndpointSend::isGone()} so the attempt prunes it, and any other status,
 * a dropped socket, or a missing response is a plain failure. A send failure is a
 * domain sentence, never an exception out of {@see PushEndpointSend::tick()}. A send
 * whose request could not be built is pre-settled through {@see PushEndpointSend::failed()}.
 */
final class PushEndpointSendTest extends TestCase
{
    public function testStaysBusyWhileTheClientIsBusy(): void
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('isBusy')->willReturn(true);
        $client->method('hasResult')->willReturn(false);
        $send = new PushEndpointSend('https://push.example/endpoint/a', $client);

        self::assertTrue($send->isBusy());
        $send->tick(1.0);

        self::assertTrue($send->isBusy());
        self::assertFalse($send->isDelivered());
        self::assertFalse($send->isGone());
    }

    public function testA2xxSettlesDeliveredAndConsumesTheResponse(): void
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('isBusy')->willReturn(false);
        $client->method('hasResult')->willReturn(true);
        $client->expects(self::once())->method('consumeResult')->willReturn(new AsyncHttpResponse(201, '', ''));
        $send = new PushEndpointSend('https://push.example/endpoint/a', $client);

        $send->tick(1.0);

        self::assertFalse($send->isBusy());
        self::assertTrue($send->isDelivered());
        self::assertFalse($send->isGone());
        self::assertNull($send->errorDetail());
    }

    public function testA404MarksTheEndpointGone(): void
    {
        $send = $this->settledFrom(new AsyncHttpStatusException(404, ''));

        self::assertFalse($send->isBusy());
        self::assertFalse($send->isDelivered());
        self::assertTrue($send->isGone());
        self::assertSame('push service returned status 404', $send->errorDetail());
    }

    public function testA410MarksTheEndpointGone(): void
    {
        $send = $this->settledFrom(new AsyncHttpStatusException(410, ''));

        self::assertTrue($send->isGone());
        self::assertSame('push service returned status 410', $send->errorDetail());
    }

    public function testAnyOtherStatusIsAPlainFailure(): void
    {
        $send = $this->settledFrom(new AsyncHttpStatusException(500, ''));

        self::assertFalse($send->isBusy());
        self::assertFalse($send->isDelivered());
        self::assertFalse($send->isGone());
        self::assertSame('push service returned status 500', $send->errorDetail());
    }

    public function testATransportErrorIsAPlainFailure(): void
    {
        $send = $this->settledFrom(new AsyncHttpException('connect timed out'));

        self::assertFalse($send->isDelivered());
        self::assertFalse($send->isGone());
        self::assertSame('push send failed: connect timed out', $send->errorDetail());
    }

    public function testADroppedSocketIsAPlainFailure(): void
    {
        $send = $this->settledFrom(new SocketException('peer reset'));

        self::assertFalse($send->isDelivered());
        self::assertSame('push send failed: peer reset', $send->errorDetail());
    }

    public function testAMissingResponseIsAPlainFailure(): void
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('isBusy')->willReturn(false);
        $client->method('hasResult')->willReturn(false);
        $send = new PushEndpointSend('https://push.example/endpoint/a', $client);

        $send->tick(1.0);

        self::assertFalse($send->isBusy());
        self::assertFalse($send->isDelivered());
        self::assertSame('push service produced no response', $send->errorDetail());
    }

    public function testTickAfterSettlingDoesNotDriveTheClientAgain(): void
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('isBusy')->willReturn(false);
        $client->method('hasResult')->willReturn(true);
        $client->expects(self::once())->method('consumeResult')->willReturn(new AsyncHttpResponse(200, '', ''));
        $send = new PushEndpointSend('https://push.example/endpoint/a', $client);

        $send->tick(1.0);
        $send->tick(2.0);

        self::assertTrue($send->isDelivered());
    }

    public function testFailedFactoryProducesAPreSettledFailure(): void
    {
        $send = PushEndpointSend::failed('https://push.example/endpoint/a', 'could not build request');

        self::assertSame('https://push.example/endpoint/a', $send->endpoint);
        self::assertFalse($send->isBusy());
        self::assertFalse($send->isDelivered());
        self::assertFalse($send->isGone());
        self::assertSame('could not build request', $send->errorDetail());
    }

    public function testCloseReleasesTheClient(): void
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->expects(self::once())->method('reset');
        $send = new PushEndpointSend('https://push.example/endpoint/a', $client);

        $send->close();
    }

    /**
     * Ticks a send whose client throws the given exception and returns it settled.
     *
     * @param Throwable $thrown Exception the client raises from tick
     * @return PushEndpointSend The settled send
     */
    private function settledFrom(\Throwable $thrown): PushEndpointSend
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('tick')->willThrowException($thrown);
        $send = new PushEndpointSend('https://push.example/endpoint/a', $client);

        $send->tick(1.0);

        return $send;
    }
}

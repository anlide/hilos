<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push\Delivery;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\Hilos;
use Hilos\Push\Delivery\PushDeliveryAttempt;
use Hilos\Push\Delivery\PushEndpointSend;
use PHPUnit\Framework\TestCase;

/**
 * Tests the multi-endpoint fan-out that is one notification's push delivery (HIL-199).
 *
 * A push attempt holds one {@see PushEndpointSend} per subscribed device and pumps them
 * all each tick, settling only once every endpoint has. It reports delivered when at
 * least one device accepted the push (the delivery row is one row for the whole channel),
 * and a domain failure sentence otherwise. An attempt over zero endpoints settles
 * immediately as a non-delivered failure. Gone endpoints are pruned best-effort; with no
 * DB context the prune is skipped without throwing.
 */
final class PushDeliveryAttemptTest extends TestCase
{
    private mixed $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        // No DB context: the best-effort prune of gone endpoints short-circuits.
        Hilos::$db = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        parent::tearDown();
    }

    public function testEmptyEndpointsSettleImmediatelyAsFailure(): void
    {
        $attempt = new PushDeliveryAttempt([]);

        $attempt->tick(1.0);

        self::assertFalse($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());
        self::assertSame('no push endpoints for recipient', $attempt->errorDetail());
    }

    public function testDeliveredWhenAtLeastOneEndpointAccepts(): void
    {
        $attempt = new PushDeliveryAttempt([
            $this->deliveredSend('https://push.example/a'),
            PushEndpointSend::failed('https://push.example/b', 'endpoint unreachable'),
        ]);

        $attempt->tick(1.0);

        self::assertFalse($attempt->isBusy());
        self::assertTrue($attempt->isDelivered());
        self::assertNull($attempt->errorDetail());
    }

    public function testFailsWhenEveryEndpointFails(): void
    {
        $attempt = new PushDeliveryAttempt([
            PushEndpointSend::failed('https://push.example/a', 'first device rejected'),
            PushEndpointSend::failed('https://push.example/b', 'second device rejected'),
        ]);

        $attempt->tick(1.0);

        self::assertFalse($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());
        self::assertSame('2 of 2 push endpoints failed: first device rejected', $attempt->errorDetail());
    }

    public function testStaysBusyUntilEveryEndpointSettles(): void
    {
        $busyClient = $this->createMock(AsyncHttpClient::class);
        $busyClient->method('isBusy')->willReturn(true);
        $attempt = new PushDeliveryAttempt([
            $this->deliveredSend('https://push.example/a'),
            new PushEndpointSend('https://push.example/b', $busyClient),
        ]);

        $attempt->tick(1.0);

        self::assertTrue($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());
    }

    public function testGoneEndpointSettlesWithoutPruningWhenNoDbContext(): void
    {
        $goneClient = $this->createMock(AsyncHttpClient::class);
        $goneClient->method('tick')->willThrowException(new AsyncHttpStatusException(410, ''));
        $attempt = new PushDeliveryAttempt([
            new PushEndpointSend('https://push.example/gone', $goneClient),
        ]);

        $attempt->tick(1.0);

        self::assertFalse($attempt->isBusy());
        self::assertFalse($attempt->isDelivered());
        self::assertSame('1 of 1 push endpoints failed: push service returned status 410', $attempt->errorDetail());
    }

    public function testCloseReleasesEveryEndpointSend(): void
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->expects(self::once())->method('reset');
        $attempt = new PushDeliveryAttempt([
            new PushEndpointSend('https://push.example/a', $client),
        ]);

        $attempt->close();
    }

    /**
     * Builds a send wired to a client that settles as a 2xx delivery.
     *
     * @param string $endpoint Target endpoint URL
     * @return PushEndpointSend A send that delivers on its next tick
     */
    private function deliveredSend(string $endpoint): PushEndpointSend
    {
        $client = $this->createMock(AsyncHttpClient::class);
        $client->method('isBusy')->willReturn(false);
        $client->method('hasResult')->willReturn(true);
        $client->method('consumeResult')->willReturn(new AsyncHttpResponse(200, '', ''));

        return new PushEndpointSend($endpoint, $client);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push\DTO;

use Hilos\BaseDTO;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Push\DTO\PushSubscribeActionDTO;
use Hilos\Push\DTO\PushUnsubscribeActionDTO;
use Hilos\Push\PushSubscriptionAction;
use PHPUnit\Framework\TestCase;

/**
 * Tests the client → server push toggle action payloads (HIL-199).
 *
 * The subscribe payload carries the browser subscription (endpoint plus the p256dh /
 * auth keys and the device user agent); the unsubscribe payload carries just the
 * endpoint. Both round-trip through {@see BaseDTO::toArray()} / fromArray, unwrap the
 * FIELD_DATA envelope on the way in, name their action, and validate that the
 * required address material is present. The acting user is never carried here.
 */
final class PushSubscriptionActionDTOTest extends TestCase
{
    public function testSubscribeRoundTripsAndNamesItsAction(): void
    {
        $dto = new PushSubscribeActionDTO(
            endpoint: 'https://push.example/endpoint/a',
            p256dh: 'client-public-key',
            auth: 'client-auth-secret',
            userAgent: 'Firefox/128',
        );

        $restored = PushSubscribeActionDTO::fromArray($dto->toArray());

        self::assertSame('https://push.example/endpoint/a', $restored->endpoint);
        self::assertSame('client-public-key', $restored->p256dh);
        self::assertSame('client-auth-secret', $restored->auth);
        self::assertSame('Firefox/128', $restored->userAgent);
        self::assertSame(PushSubscriptionAction::SUBSCRIBE, $restored->getAction());
        self::assertTrue($restored->isValid());
    }

    public function testSubscribeUnwrapsTheDataEnvelope(): void
    {
        $dto = PushSubscribeActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                PushSubscribeActionDTO::endpoint => 'https://push.example/endpoint/b',
                PushSubscribeActionDTO::p256dh => 'pk',
                PushSubscribeActionDTO::auth => 'auth',
            ],
        ]);

        self::assertSame('https://push.example/endpoint/b', $dto->endpoint);
        self::assertSame('pk', $dto->p256dh);
        self::assertSame('auth', $dto->auth);
        self::assertNull($dto->userAgent);
        self::assertTrue($dto->isValid());
    }

    public function testSubscribeIsInvalidWhenAddressMaterialIsMissing(): void
    {
        self::assertFalse(new PushSubscribeActionDTO('', 'pk', 'auth', null)->isValid());
        self::assertFalse(new PushSubscribeActionDTO('https://push.example/a', '', 'auth', null)->isValid());
        self::assertFalse(new PushSubscribeActionDTO('https://push.example/a', 'pk', '', null)->isValid());
    }

    public function testUnsubscribeRoundTripsAndNamesItsAction(): void
    {
        $dto = new PushUnsubscribeActionDTO(endpoint: 'https://push.example/endpoint/a');
        $restored = PushUnsubscribeActionDTO::fromArray($dto->toArray());

        self::assertSame('https://push.example/endpoint/a', $restored->endpoint);
        self::assertSame(PushSubscriptionAction::UNSUBSCRIBE, $restored->getAction());
        self::assertTrue($restored->isValid());
    }

    public function testUnsubscribeUnwrapsTheDataEnvelope(): void
    {
        $dto = PushUnsubscribeActionDTO::fromArray([
            SignalPayloadConstants::FIELD_DATA => [
                PushUnsubscribeActionDTO::endpoint => 'https://push.example/endpoint/c',
            ],
        ]);

        self::assertSame('https://push.example/endpoint/c', $dto->endpoint);
        self::assertTrue($dto->isValid());
    }

    public function testUnsubscribeIsInvalidWithoutAnEndpoint(): void
    {
        self::assertFalse(new PushUnsubscribeActionDTO('')->isValid());
    }
}

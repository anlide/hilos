<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Push;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests the DB-free contract of the push-subscription accessor (HIL-199).
 *
 * A project registers the subscription collection unconditionally but may not have
 * activated the hilos_push_subscription table (no migration). The send-target read
 * {@see ObjectPushSubscriptions::forUser()} must then degrade to an empty list rather
 * than query a missing table, keeping push delivery inert until a project activates
 * it - mirroring the notification-preference gate (HIL-485). An empty endpoint is
 * rejected on the opt-in write and ignored on opt-out, both before any query. The
 * DB-backed upsert/delete are exercised at integration / e2e (HIL-202).
 */
final class PushSubscriptionsCollectionTest extends TestCase
{
    public function testForUserDegradesToEmptyWhenTableNotActivated(): void
    {
        Schema::reset();
        $subscriptions = ObjectPushSubscriptions::initDB(Objects::LAZY_STRATEGY_KEY);

        self::assertSame([], $subscriptions->forUser(1));
    }

    public function testSubscribeRejectsAnEmptyEndpoint(): void
    {
        $subscriptions = ObjectPushSubscriptions::initDB(Objects::LAZY_STRATEGY_KEY);

        $this->expectException(EmptyValueException::class);
        $subscriptions->subscribe(1, '', 'pk', 'auth', null);
    }

    public function testUnsubscribeIgnoresAnEmptyEndpoint(): void
    {
        $subscriptions = ObjectPushSubscriptions::initDB(Objects::LAZY_STRATEGY_KEY);

        $subscriptions->unsubscribe('');

        $this->expectNotToPerformAssertions();
    }
}

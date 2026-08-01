<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\NotificationTypeDescriptor;
use Hilos\Notification\NotificationTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the LSB loss on the notification registry accessors (HIL-489).
 *
 * Framework call-sites reach the channel/type registries through the bare
 * `Hilos::notificationChannelRegistryClass()` / `...TypeRegistryClass()` accessors,
 * which bind late static resolution to the abstract base facade rather than the
 * project subclass, so a project override was invisible and the dispatcher/admin
 * table saw the empty base registry. This exercises exactly the framework path
 * (no channels()-seam injection): after a project facade initializes, the bare
 * base-class accessors must resolve to the project's concrete registries.
 */
final class NotificationRegistryLsbResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testBareBaseAccessorsResolveProjectRegistriesAfterInit(): void
    {
        // Sanity: without a project facade captured, the base accessors see the empty base.
        self::assertSame(DeliveryChannelRegistry::class, Hilos::notificationChannelRegistryClass());
        self::assertSame(NotificationTypeRegistry::class, Hilos::notificationTypeRegistryClass());

        LsbResolutionTestHilos::initBrowser();

        // The bug: these bare `Hilos::` calls used to bind to the abstract base.
        self::assertSame(
            LsbResolutionTestChannelRegistry::class,
            Hilos::notificationChannelRegistryClass(),
        );
        self::assertSame(
            LsbResolutionTestTypeRegistry::class,
            Hilos::notificationTypeRegistryClass(),
        );

        // The concrete project registry content is now visible through the framework path.
        self::assertSame(
            ['email'],
            array_keys(Hilos::notificationChannelRegistryClass()::all()),
        );
        self::assertTrue(Hilos::notificationTypeRegistryClass()::isMandatory('security.alert'));
    }
}

/**
 * Project facade fixture pointing the registry constants at its own subclasses.
 */
final class LsbResolutionTestHilos extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = LsbResolutionTestChannelRegistry::class;

    protected const string NOTIFICATION_TYPE_REGISTRY = LsbResolutionTestTypeRegistry::class;

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new LsbResolutionTestDbContext();
    }
}

/**
 * No-op DB context so the abstract facade fixture is instantiable.
 */
final class LsbResolutionTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the LSB resolution fixture.
     */
    public function configure(): void
    {
    }
}

/**
 * Project channel registry composing one channel onto the empty base.
 */
final class LsbResolutionTestChannelRegistry extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return array_replace(parent::channels(), [
            'email' => new LsbResolutionTestChannel(),
        ]);
    }
}

/**
 * Minimal pooled channel fixture for the registry override.
 */
final class LsbResolutionTestChannel extends AbstractDeliveryChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function deliverSignalName(): string
    {
        return 'hilos_notification_deliver_email';
    }

    public function resolveAddress(int $userId): ?string
    {
        return 'user-' . $userId . '@example.test';
    }
}

/**
 * Project type registry declaring one mandatory type.
 */
final class LsbResolutionTestTypeRegistry extends NotificationTypeRegistry
{
    protected static function types(): array
    {
        return array_replace(parent::types(), [
            'security.alert' => new NotificationTypeDescriptor(mandatory: true),
        ]);
    }
}

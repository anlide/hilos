<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelOwners;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Channel owner sets require only topology declarations and the current pool width. */
final class DeliveryChannelOwnersTest extends TestCase
{
    private string $previousApp;

    /** Installs a process-local catalog without database or runtime initialization. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        ChannelOwnersHilos::initBrowser();
        ChannelOwnersRegistry::$channels = ['project_owners' => new OwnersTestChannel()];
    }

    /** Restores the facade and discards this case's descriptor catalog. */
    protected function tearDown(): void
    {
        ChannelOwnersRegistry::$channels = [];
        $this->previousApp::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /** Every pool slot is a string, and a later read observes the current width. */
    public function testPooledOwnersHaveOneBasedStringIndexes(): void
    {
        $owners = DeliveryChannelOwners::of('project_owners');
        self::assertSame('project_owners', $owners->channel);
        self::assertSame(OwnersPoolAgent::AGENT_TYPE, $owners->agentType);
        self::assertSame(['1', '2', '3'], $owners->agentIndexes);

        ChannelOwnersRegistry::$channels['project_owners'] = new OwnersTestChannel(width: 1);
        self::assertSame(['1'], DeliveryChannelOwners::of('project_owners')->agentIndexes);
    }

    /** A singleton has exactly its null index and never asks for a pool width. */
    public function testSingletonOwnerHasANullIndex(): void
    {
        ChannelOwnersRegistry::$channels['project_owners'] = new OwnersTestChannel(pooled: false);
        $owners = DeliveryChannelOwners::of('project_owners');

        self::assertSame('project_owners', $owners->channel);
        self::assertSame(OwnersSingletonAgent::AGENT_TYPE, $owners->agentType);
        self::assertSame([null], $owners->agentIndexes);
    }

    /** @param int $width Nonpositive declared pool width */
    #[DataProvider('invalidPoolWidths')]
    public function testInvalidPoolIsRefusedRatherThanTreatedAsASingleton(int $width): void
    {
        ChannelOwnersRegistry::$channels['project_owners'] = new OwnersTestChannel(width: $width);

        $this->expectException(InvalidTopologyException::class);
        DeliveryChannelOwners::of('project_owners');
    }

    /** @return iterable<string, array{int}> Zero and negative pool widths */
    public static function invalidPoolWidths(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    /** An absent descriptor cannot produce an owner set. */
    public function testUnregisteredChannelIsRefused(): void
    {
        $this->expectException(InvalidTopologyException::class);
        DeliveryChannelOwners::of('missing');
    }

    /** A registered descriptor still requires an agent route. */
    public function testMissingRouteIsRefused(): void
    {
        ChannelOwnersNoRoutesHilos::initBrowser();

        $this->expectException(InvalidTopologyException::class);
        DeliveryChannelOwners::of('project_owners');
    }

    /** Two registrations for the delivery signal cannot select one owner. */
    public function testAmbiguousRouteIsRefused(): void
    {
        ChannelOwnersAmbiguousHilos::initBrowser();

        $this->expectException(InvalidTopologyException::class);
        DeliveryChannelOwners::of('project_owners');
    }

    /** Saved source keys reduce to members of the declared three-slot pool. */
    public function testReducedDeliveryIndexesBelongToTheOwnerSet(): void
    {
        $owners = DeliveryChannelOwners::of('project_owners');
        foreach ([0, 1, 2, 3, 4, 27, 91, PHP_INT_MAX] as $key) {
            self::assertContains(
                (string)ChannelOwnersRegistry::$channels['project_owners']->ownerIndexFor($key),
                $owners->agentIndexes,
            );
        }
    }

    /** Environment failures retain the exact exception rather than becoming topology errors. */
    public function testPoolEnvironmentFailurePropagatesUnchanged(): void
    {
        $failure = new EnvException('Pool environment is unavailable');
        ChannelOwnersRegistry::$channels['project_owners'] = new OwnersTestChannel(poolFailure: $failure);

        $this->expectExceptionObject($failure);
        DeliveryChannelOwners::of('project_owners');
    }
}

/** Mutable descriptors scoped to this test process and cleared after every case. */
final class ChannelOwnersRegistry extends DeliveryChannelRegistry
{
    /** @var array<string, AbstractDeliveryChannel> Descriptors for the current case */
    public static array $channels = [];

    /** @return array<string, AbstractDeliveryChannel> Descriptors for the current case */
    protected static function channels(): array
    {
        return self::$channels;
    }
}

abstract class ChannelOwnersHilos extends Hilos
{
    public const array AGENTS = [
        OwnersSingletonAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => OwnersSingletonAgent::class],
        OwnersPoolAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => OwnersPoolAgent::class, AgentRegistryKey::INDEXED => true],
    ];

    protected const string NOTIFICATION_CHANNEL_REGISTRY = ChannelOwnersRegistry::class;
}

abstract class ChannelOwnersNoRoutesHilos extends ChannelOwnersHilos
{
    public const array AGENTS = [];
}

abstract class ChannelOwnersAmbiguousHilos extends ChannelOwnersHilos
{
    public const array AGENTS = [
        OwnersPoolAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => OwnersPoolAgent::class, AgentRegistryKey::INDEXED => true],
        'duplicate_pool' => [AgentRegistryKey::WORKER => OwnersPoolAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

/** Fails immediately if owner enumeration reaches recipient, settings or transport seams. */
final class OwnersTestChannel extends AbstractDeliveryChannel
{
    /**
     * @param bool $pooled Whether this case declares a pool
     * @param int $width Declared pool width
     * @param ?EnvException $poolFailure Environment failure to propagate unchanged
     */
    public function __construct(
        private readonly bool $pooled = true,
        private readonly int $width = 3,
        private readonly ?EnvException $poolFailure = null,
    ) {
    }

    /** @return string Test channel registry key */
    public function name(): string
    {
        return 'project_owners';
    }

    /** @return string Signal matching this case's declared owner shape */
    public function deliverSignalName(): string
    {
        return $this->pooled ? OwnersPoolAgent::SIGNAL : OwnersSingletonAgent::SIGNAL;
    }

    /** @return bool Whether the declared owner is indexed */
    public function isPooled(): bool
    {
        return $this->pooled;
    }

    /** @return int Current case's pool width */
    public function poolSize(): int
    {
        if (!$this->pooled) {
            TestCase::fail('Singleton owner enumeration must not read a pool width');
        }
        if ($this->poolFailure !== null) {
            throw $this->poolFailure;
        }

        return $this->width;
    }

    /**
     * @param int $userId Recipient whose address must remain unread
     * @return ?string Never returns an address
     */
    public function resolveAddress(int $userId): ?string
    {
        TestCase::fail('Owner enumeration must not resolve an address');
    }

    /** @return string Never returns an enablement key */
    public function enabledSettingKey(): string
    {
        TestCase::fail('Owner enumeration must not read channel enablement');
    }

    /** @return array Never returns transport configuration fields */
    public function configFields(): array
    {
        TestCase::fail('Owner enumeration must not read transport configuration');
    }

    /** @return array Never returns browser transport configuration */
    public function frontendSubscribeConfig(): array
    {
        TestCase::fail('Owner enumeration must not read browser transport configuration');
    }

    /**
     * @param int $userId Recipient whose routing key must remain unread
     * @param int $notificationId Notification whose routing key must remain unread
     * @return ?int Never returns a routing key
     */
    public function routingKeyFor(int $userId, int $notificationId): ?int
    {
        TestCase::fail('Owner enumeration must not calculate a recipient routing key');
    }
}

class OwnersSingletonAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'owners_singleton';
    public const string SIGNAL = 'owners_singleton_deliver';
    public const array AGENT_SIGNALS = [self::SIGNAL];

    /** This descriptor-only fixture holds no resources to release. */
    public function onStop(): void
    {
    }
}

final class OwnersPoolAgent extends OwnersSingletonAgent
{
    public const string AGENT_TYPE = 'owners_pool';
    public const string SIGNAL = 'owners_pool_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];
}

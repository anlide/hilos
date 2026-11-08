<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification\Delivery;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\HilosMailer;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryOwnerResolver;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\HilosSmsSender;
use PHPUnit\Framework\TestCase;

/** The real resolver reads saved scalars and the active descriptor/agent declarations. */
final class DeliveryOwnerResolverTest extends TestCase
{
    private string $previousApp;
    private ?EnvAccessor $previousEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        DeliveryResolverHilos::initBrowser();
        DeliveryResolverRegistry::$channels = ['project' => new ResolverProjectChannel()];
    }

    protected function tearDown(): void
    {
        DeliveryResolverRegistry::$channels = [];
        Hilos::$env = $this->previousEnv;
        $this->previousApp::initBrowser();
        Hilos::resetBrowser();
        putenv('MAIL_WORKER_COUNT');
        putenv('SMS_WORKER_COUNT');
        putenv('PUSH_WORKER_COUNT');
        parent::tearDown();
    }

    public function testSingletonNeedsNoIndexAndNoRecipientLookup(): void
    {
        self::assertEquals(new AgentId(ResolverSingletonAgent::AGENT_TYPE), DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(91, 12, 'project', null),
        ));
    }

    public function testProjectPoolUsesSavedSourceKey(): void
    {
        $this->projectPool();
        self::assertEquals(new AgentId(ResolverPoolAgent::AGENT_TYPE, '4'), DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(91, 12, 'project', 27),
        ));
    }

    public function testLegacyNullFallsBackToNotificationIdAndZeroIsARealSourceKey(): void
    {
        $this->projectPool();
        self::assertSame('5', DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', null))?->index);
        self::assertSame('1', DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 0))?->index);
    }

    public function testChangingWidthReducesTheSavedBasisRatherThanTheOldIndex(): void
    {
        $this->projectPool();
        $saved = new NotificationDeliveryIdentity(91, 12, 'project', 27);
        self::assertSame('4', DeliveryOwnerResolver::forDelivery($saved)?->index);
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(pooled: true, width: 3, signal: ResolverPoolAgent::SIGNAL);
        self::assertSame('1', DeliveryOwnerResolver::forDelivery($saved)?->index);
    }

    public function testOwnerDoesNotDependOnParentRecipientOrCurrentAddress(): void
    {
        DeliveryResolverRegistry::$channels = [MailDeliveryChannel::NAME => $this->unreadableMail()];
        putenv('MAIL_WORKER_COUNT=8');
        $key = HilosMailer::routingKeyForAddress('User@Example.com');
        self::assertEquals(new AgentId(MailDeliveryChannelAgent::AGENT_TYPE, '2'), DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(91, 12, MailDeliveryChannel::NAME, $key),
        ));
        self::assertEquals(new AgentId(MailDeliveryChannelAgent::AGENT_TYPE, '2'), DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(92, 999999, MailDeliveryChannel::NAME, $key),
        ));
        self::assertSame('3', DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(93, 12, MailDeliveryChannel::NAME, HilosMailer::routingKeyForAddress('second@example.com')),
        )?->index);
    }

    public function testSmsAndPushUseTheirOwnKeysAndRealAgentRoutes(): void
    {
        DeliveryResolverRegistry::$channels = [
            SmsDeliveryChannel::NAME => new SmsDeliveryChannel(),
            PushDeliveryChannel::NAME => new PushDeliveryChannel(),
        ];
        putenv('SMS_WORKER_COUNT=8');
        putenv('PUSH_WORKER_COUNT=8');
        self::assertEquals(new AgentId(SmsDeliveryChannelAgent::AGENT_TYPE, '5'), DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(91, 12, SmsDeliveryChannel::NAME, HilosSmsSender::routingKeyForNumber('+1 555 123 4567')),
        ));
        self::assertEquals(new AgentId(PushDeliveryChannelAgent::AGENT_TYPE, '8'), DeliveryOwnerResolver::forDelivery(
            new NotificationDeliveryIdentity(92, 12, PushDeliveryChannel::NAME, new PushDeliveryChannel()->routingKeyFor(7, 12)),
        ));
    }

    public function testUnknownMissingAndMisnamedDescriptorsHaveNoOwner(): void
    {
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'missing', 27)));
        DeliveryResolverRegistry::$channels = [];
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
        DeliveryResolverRegistry::$channels = ['alias' => new ResolverProjectChannel()];
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'alias', 27)));
    }

    public function testDuplicateDescriptorNamesOrSignalsHaveNoOwner(): void
    {
        DeliveryResolverRegistry::$channels['duplicate'] = new ResolverProjectChannel();
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
        DeliveryResolverRegistry::$channels['duplicate'] = new ResolverProjectChannel(channel: 'other');
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
    }

    public function testMissingAndAmbiguousRoutesHaveNoOwner(): void
    {
        DeliveryResolverNoRoutesHilos::initBrowser();
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
        DeliveryResolverAmbiguousHilos::initBrowser();
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
    }

    /** Channel-only resolution agrees with saved deliveries for both owner shapes. */
    public function testChannelAndDeliveryResolveTheSameAgentType(): void
    {
        self::assertSame(ResolverSingletonAgent::AGENT_TYPE, DeliveryOwnerResolver::forChannel('project'));
        self::assertSame(
            DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', null))?->type,
            DeliveryOwnerResolver::forChannel('project'),
        );

        $this->projectPool();
        self::assertSame(ResolverPoolAgent::AGENT_TYPE, DeliveryOwnerResolver::forChannel('project'));
        self::assertSame(
            DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27))?->type,
            DeliveryOwnerResolver::forChannel('project'),
        );
    }

    /** Missing and ambiguous declarations cannot identify a channel owner. */
    public function testChannelRefusesMissingAndAmbiguousDescriptorsAndRoutes(): void
    {
        self::assertNull(DeliveryOwnerResolver::forChannel('missing'));
        DeliveryResolverRegistry::$channels['duplicate'] = new ResolverProjectChannel();
        self::assertNull(DeliveryOwnerResolver::forChannel('project'));
        DeliveryResolverRegistry::$channels['duplicate'] = new ResolverProjectChannel(channel: 'other');
        self::assertNull(DeliveryOwnerResolver::forChannel('project'));
        unset(DeliveryResolverRegistry::$channels['duplicate']);

        DeliveryResolverNoRoutesHilos::initBrowser();
        self::assertNull(DeliveryOwnerResolver::forChannel('project'));
        DeliveryResolverAmbiguousHilos::initBrowser();
        self::assertNull(DeliveryOwnerResolver::forChannel('project'));
    }

    /** The master can resolve the route before any pool or delivery key is read. */
    public function testChannelResolutionDoesNotCalculatePoolOrDeliveryCoordinates(): void
    {
        DeliveryResolverPoolHilos::initBrowser();
        $channel = $this->createMock(AbstractDeliveryChannel::class);
        $channel->method('name')->willReturn('project');
        $channel->method('deliverSignalName')->willReturn(ResolverPoolAgent::SIGNAL);
        $channel->method('isPooled')->willReturn(true);
        $channel->method('indexField')->willReturn(NotificationDeliverSignalData::shardKey);
        foreach (['poolSize', 'routingKeyFor', 'ownerIndexFor', 'resolveAddress'] as $method) {
            $channel->expects(self::never())->method($method);
        }
        DeliveryResolverRegistry::$channels['project'] = $channel;

        self::assertSame(ResolverPoolAgent::AGENT_TYPE, DeliveryOwnerResolver::forChannel('project'));
    }

    public function testPoolRequiresIndexedRegistrationAndTheShardField(): void
    {
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(pooled: true);
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
        DeliveryResolverWrongFieldHilos::initBrowser();
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(pooled: true, signal: ResolverWrongFieldAgent::SIGNAL);
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
    }

    public function testSingletonRefusesAnIndexedRegistration(): void
    {
        DeliveryResolverPoolHilos::initBrowser();
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(signal: ResolverPoolAgent::SIGNAL);
        self::assertNull(DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27)));
    }

    public function testInvalidProjectPoolWidthIsAnErrorRatherThanNoOwner(): void
    {
        $this->projectPool();
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(pooled: true, width: 0);
        $this->expectException(InvalidArgumentException::class);
        DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, 'project', 27));
    }

    public function testInvalidConfiguredPoolWidthIsNotClampedToOne(): void
    {
        DeliveryResolverRegistry::$channels = [MailDeliveryChannel::NAME => new MailDeliveryChannel()];
        putenv('MAIL_WORKER_COUNT=0');
        $this->expectException(EnvInvalidValueException::class);
        DeliveryOwnerResolver::forDelivery(new NotificationDeliveryIdentity(91, 12, MailDeliveryChannel::NAME, 27));
    }

    private function projectPool(): void
    {
        DeliveryResolverPoolHilos::initBrowser();
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(pooled: true, signal: ResolverPoolAgent::SIGNAL);
    }

    private function unreadableMail(): MailDeliveryChannel
    {
        $channel = $this->createPartialMock(MailDeliveryChannel::class, ['resolveAddress']);
        $channel->expects(self::never())->method('resolveAddress');

        return $channel;
    }
}

/** Mutable descriptor catalog local to one test. */
final class DeliveryResolverRegistry extends DeliveryChannelRegistry
{
    /** @var array<string, AbstractDeliveryChannel> Descriptors for one resolver case */
    public static array $channels = [];

    protected static function channels(): array
    {
        return self::$channels;
    }
}

abstract class DeliveryResolverHilos extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryResolverRegistry::class;

    public const array AGENTS = [
        ResolverSingletonAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverSingletonAgent::class],
        MailDeliveryChannelAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class, AgentRegistryKey::INDEXED => true],
        SmsDeliveryChannelAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => SmsDeliveryChannelAgent::class, AgentRegistryKey::INDEXED => true],
        PushDeliveryChannelAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => PushDeliveryChannelAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

abstract class DeliveryResolverPoolHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverPoolAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverPoolAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

abstract class DeliveryResolverNoRoutesHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [];
}

abstract class DeliveryResolverAmbiguousHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverSingletonAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverSingletonAgent::class],
        'duplicate' => [AgentRegistryKey::WORKER => ResolverSingletonAgent::class],
    ];
}

abstract class DeliveryResolverWrongFieldHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverWrongFieldAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverWrongFieldAgent::class, AgentRegistryKey::INDEXED => true],
    ];
}

final class ResolverProjectChannel extends AbstractDeliveryChannel
{
    public function __construct(
        private readonly bool $pooled = false,
        private readonly int $width = 8,
        private readonly string $channel = 'project',
        private readonly string $signal = ResolverSingletonAgent::SIGNAL,
    ) {
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function deliverSignalName(): string
    {
        return $this->signal;
    }

    public function resolveAddress(int $userId): ?string
    {
        TestCase::fail('Owner resolution must never resolve an address');
    }

    public function isPooled(): bool
    {
        return $this->pooled;
    }

    public function poolSize(): int
    {
        return $this->width;
    }
}

class ResolverSingletonAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'resolver_singleton';
    public const string SIGNAL = 'resolver_singleton_deliver';
    public const array AGENT_SIGNALS = [self::SIGNAL];

    public function onStop(): void
    {
    }
}

final class ResolverPoolAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_pool';
    public const string SIGNAL = 'resolver_pool_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];
}

final class ResolverWrongFieldAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_wrong_field';
    public const string SIGNAL = 'resolver_wrong_field_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => 'wrongField'],
    ];
}

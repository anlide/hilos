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
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\HilosSmsSender;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** The retry request goes to the very owner the delivery has: both built-in pools, a project pool and a singleton. */
    public function testRetryOwnerIsTheDeliveryOwnerForBuiltInPoolsProjectPoolAndSingleton(): void
    {
        DeliveryResolverRegistry::$channels = [
            MailDeliveryChannel::NAME => $this->unreadableMail(),
            SmsDeliveryChannel::NAME => new SmsDeliveryChannel(),
            PushDeliveryChannel::NAME => new PushDeliveryChannel(),
        ];
        putenv('MAIL_WORKER_COUNT=8');
        putenv('SMS_WORKER_COUNT=8');
        putenv('PUSH_WORKER_COUNT=8');
        $saved = [
            new NotificationDeliveryIdentity(91, 12, MailDeliveryChannel::NAME, HilosMailer::routingKeyForAddress('User@Example.com')),
            new NotificationDeliveryIdentity(92, 12, SmsDeliveryChannel::NAME, HilosSmsSender::routingKeyForNumber('+1 555 123 4567')),
            new NotificationDeliveryIdentity(93, 12, PushDeliveryChannel::NAME, new PushDeliveryChannel()->routingKeyFor(7, 12)),
        ];
        $expected = [
            new AgentId(MailDeliveryChannelAgent::AGENT_TYPE, '2'),
            new AgentId(SmsDeliveryChannelAgent::AGENT_TYPE, '5'),
            new AgentId(PushDeliveryChannelAgent::AGENT_TYPE, '8'),
        ];
        foreach ($saved as $position => $identity) {
            self::assertEquals($expected[$position], DeliveryOwnerResolver::forRetry($identity));
            self::assertEquals(DeliveryOwnerResolver::forDelivery($identity), DeliveryOwnerResolver::forRetry($identity));
        }

        DeliveryResolverRegistry::$channels = ['project' => new ResolverProjectChannel()];
        self::assertEquals(new AgentId(ResolverSingletonAgent::AGENT_TYPE), DeliveryOwnerResolver::forRetry(
            new NotificationDeliveryIdentity(91, 12, 'project', null),
        ));

        $this->projectPool();
        $projectSaved = new NotificationDeliveryIdentity(91, 12, 'project', 27);
        self::assertEquals(new AgentId(ResolverPoolAgent::AGENT_TYPE, '4'), DeliveryOwnerResolver::forRetry($projectSaved));
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(
            pooled: true,
            width: 3,
            signal: ResolverPoolAgent::SIGNAL,
        );
        self::assertSame('1', DeliveryOwnerResolver::forRetry($projectSaved)?->index);
    }

    /** Without the delivery owner, or without the channel's retry registration, the request has nowhere to go. */
    public function testMissingOrDuplicateRetryRouteAndUnresolvedDeliveryHaveNoRetryOwner(): void
    {
        $saved = new NotificationDeliveryIdentity(91, 12, 'project', null);
        self::assertNull(DeliveryOwnerResolver::forRetry(new NotificationDeliveryIdentity(91, 12, 'missing', 27)));
        self::assertNull(DeliveryOwnerResolver::forRetry(new NotificationDeliveryIdentity(91, 12, 'alias', 27)));

        DeliveryResolverNoRoutesHilos::initBrowser();
        self::assertNull(DeliveryOwnerResolver::forRetry($saved));

        DeliveryResolverNoRetryHilos::initBrowser();
        self::assertSame(ResolverNoRetryAgent::AGENT_TYPE, DeliveryOwnerResolver::forDelivery($saved)?->type);
        self::assertNull(DeliveryOwnerResolver::forRetry($saved));

        DeliveryResolverTwinRetryHilos::initBrowser();
        self::assertSame(ResolverSingletonAgent::AGENT_TYPE, DeliveryOwnerResolver::forDelivery($saved)?->type);
        self::assertNull(DeliveryOwnerResolver::forRetry($saved));

        DeliveryResolverHilos::initBrowser();
        DeliveryResolverRegistry::$channels['twin'] = new ResolverProjectChannel(
            channel: 'twin',
            signal: 'resolver_twin_deliver',
            retrySignal: ResolverSingletonAgent::RETRY_SIGNAL,
        );
        self::assertSame(ResolverSingletonAgent::AGENT_TYPE, DeliveryOwnerResolver::forDelivery($saved)?->type);
        self::assertNull(DeliveryOwnerResolver::forRetry($saved));
    }

    /** A retry route on another worker, with another index field or another DTO is no owner, and never the delivery route instead. */
    #[DataProvider('disagreeingRetryRoutes')]
    public function testRetryRouteOnAnotherWorkerIndexOrDtoHasNoOwner(string $agent, bool $pooled): void
    {
        DeliveryResolverRetryFaultsHilos::initBrowser();
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel(pooled: $pooled, signal: $agent::SIGNAL);
        $saved = new NotificationDeliveryIdentity(91, 12, 'project', 27);

        self::assertSame($agent::AGENT_TYPE, DeliveryOwnerResolver::forDelivery($saved)?->type);
        self::assertNull(DeliveryOwnerResolver::forRetry($saved));
    }

    /** The only retry registration, right payload and right index field, sits on another worker than the delivery route: no owner. */
    public function testRetryRouteRegisteredOnAnotherWorkerThanTheDeliveryRouteHasNoOwner(): void
    {
        DeliveryResolverRetryOnAnotherWorkerHilos::initBrowser();
        DeliveryResolverRegistry::$channels['project'] = new ResolverProjectChannel();
        $saved = new NotificationDeliveryIdentity(91, 12, 'project', null);

        self::assertEquals(new AgentId(ResolverNoRetryAgent::AGENT_TYPE), DeliveryOwnerResolver::forDelivery($saved));
        self::assertNull(DeliveryOwnerResolver::forRetry($saved));
    }

    /** @return array<string, array{class-string<ResolverSingletonAgent>, bool}> Agent declaring the disagreeing retry route, pooled channel */
    public static function disagreeingRetryRoutes(): array
    {
        return [
            'pool routes retry on another field' => [ResolverWrongRetryFieldAgent::class, true],
            'pool routes retry without a field' => [ResolverNoRetryFieldAgent::class, true],
            'singleton routes retry by a field' => [ResolverIndexedRetryAgent::class, false],
            'retry carries the delivery DTO' => [ResolverWrongRetryDtoAgent::class, false],
            'retry declared without a DTO' => [ResolverBareRetryAgent::class, false],
        ];
    }

    /** The retry request is a read of the registry: no notification, recipient or address is needed to find the owner. */
    public function testRetryOwnerReadsNoNotificationAndNoAddress(): void
    {
        DeliveryResolverRegistry::$channels = [MailDeliveryChannel::NAME => $this->unreadableMail()];
        putenv('MAIL_WORKER_COUNT=8');
        $key = HilosMailer::routingKeyForAddress('User@Example.com');
        self::assertEquals(new AgentId(MailDeliveryChannelAgent::AGENT_TYPE, '2'), DeliveryOwnerResolver::forRetry(
            new NotificationDeliveryIdentity(91, 12, MailDeliveryChannel::NAME, $key),
        ));
        self::assertEquals(new AgentId(MailDeliveryChannelAgent::AGENT_TYPE, '2'), DeliveryOwnerResolver::forRetry(
            new NotificationDeliveryIdentity(92, 999999, MailDeliveryChannel::NAME, $key),
        ));
        putenv('MAIL_WORKER_COUNT=0');
        $this->expectException(EnvInvalidValueException::class);
        DeliveryOwnerResolver::forRetry(new NotificationDeliveryIdentity(93, 12, MailDeliveryChannel::NAME, $key));
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

abstract class DeliveryResolverNoRetryHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverNoRetryAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverNoRetryAgent::class],
    ];
}

abstract class DeliveryResolverTwinRetryHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverSingletonAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverSingletonAgent::class],
        ResolverRetryTwinAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverRetryTwinAgent::class],
    ];
}

/** Delivery route on one worker, the channel's only retry registration on another. */
abstract class DeliveryResolverRetryOnAnotherWorkerHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverNoRetryAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverNoRetryAgent::class],
        ResolverRetryTwinAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverRetryTwinAgent::class],
    ];
}

abstract class DeliveryResolverRetryFaultsHilos extends DeliveryResolverHilos
{
    public const array AGENTS = [
        ResolverWrongRetryFieldAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ResolverWrongRetryFieldAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
        ResolverNoRetryFieldAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverNoRetryFieldAgent::class, AgentRegistryKey::INDEXED => true],
        ResolverIndexedRetryAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverIndexedRetryAgent::class],
        ResolverWrongRetryDtoAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverWrongRetryDtoAgent::class],
        ResolverBareRetryAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => ResolverBareRetryAgent::class],
    ];
}

final class ResolverProjectChannel extends AbstractDeliveryChannel
{
    public function __construct(
        private readonly bool $pooled = false,
        private readonly int $width = 8,
        private readonly string $channel = 'project',
        private readonly string $signal = ResolverSingletonAgent::SIGNAL,
        private readonly ?string $retrySignal = null,
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

    public function retrySignalName(): string
    {
        return $this->retrySignal ?? $this->signal . '_retry';
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
    public const string RETRY_SIGNAL = self::SIGNAL . '_retry';
    public const array AGENT_SIGNALS = [
        self::SIGNAL,
        self::RETRY_SIGNAL => [AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class],
    ];

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
        self::SIGNAL . '_retry' => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliveryRetrySignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class,
        ],
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

/** Takes deliveries only: the channel's retry signal has no registration here. */
final class ResolverNoRetryAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_no_retry';
    public const string SIGNAL = ResolverSingletonAgent::SIGNAL;
    public const array AGENT_SIGNALS = [self::SIGNAL];
}

/** Registers the singleton's retry signal a second time, on another worker. */
final class ResolverRetryTwinAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_retry_twin';
    public const array AGENT_SIGNALS = [
        ResolverSingletonAgent::RETRY_SIGNAL => [AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class],
    ];
}

/** Pool whose retry route fans on a field the descriptor does not declare. */
final class ResolverWrongRetryFieldAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_wrong_retry_field';
    public const string SIGNAL = 'resolver_wrong_retry_field_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
        self::SIGNAL . '_retry' => [
            AgentSignalConfigKey::INDEX_FIELD => 'wrongField',
            AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class,
        ],
    ];
}

/** Pool whose retry route has no index field at all. */
final class ResolverNoRetryFieldAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_no_retry_field';
    public const string SIGNAL = 'resolver_no_retry_field_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
        self::SIGNAL . '_retry' => [AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class],
    ];
}

/** Singleton whose retry route fans by a shard field the singleton descriptor does not have. */
final class ResolverIndexedRetryAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_indexed_retry';
    public const string SIGNAL = 'resolver_indexed_retry_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL,
        self::SIGNAL . '_retry' => [
            AgentSignalConfigKey::INDEX_FIELD => NotificationDeliveryRetrySignalData::shardKey,
            AgentSignalConfigKey::DTO => NotificationDeliveryRetrySignalData::class,
        ],
    ];
}

/** Singleton whose retry route carries the delivery payload instead of the retry one. */
final class ResolverWrongRetryDtoAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_wrong_retry_dto';
    public const string SIGNAL = 'resolver_wrong_retry_dto_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL,
        self::SIGNAL . '_retry' => [AgentSignalConfigKey::DTO => NotificationDeliverSignalData::class],
    ];
}

/** Singleton whose retry signal is a bare name with no payload class. */
final class ResolverBareRetryAgent extends ResolverSingletonAgent
{
    public const string AGENT_TYPE = 'resolver_bare_retry';
    public const string SIGNAL = 'resolver_bare_retry_deliver';
    public const array AGENT_SIGNALS = [self::SIGNAL, self::SIGNAL . '_retry'];
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\TruthSource\DbWriterPartition;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsRawSendAgent;
use PHPUnit\Framework\TestCase;

/** Real mounted writes with partition metadata confined to registered test delivery workers. */
abstract class NotificationDeliveryOwnershipIntegrationTestCase extends NotificationDeliveryApiIntegrationTestCase
{
    protected DeliveryOwnershipAnnouncements $announcements;

    private string $previousApp;
    /** @var list<string> Per-case claims to release without changing the framework harness's claims */
    private array $ownershipClaims = [];

    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousApp = Hilos::appClass();
        parent::prepareIntegrationEnvironment();
        DeliveryOwnershipHilos::initBrowser();
        DeliveryOwnershipChannels::$width = 2;
        $this->announcements = new DeliveryOwnershipAnnouncements();
        SourceChangeBus::subscribe($this->announcements);
    }

    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach ($this->ownershipClaims as $agent) {
                TruthSourceRegistry::unregisterAgent($agent);
            }
            $this->ownershipClaims = [];
            DeliveryOwnershipChannels::$width = 2;
            parent::releaseIntegrationEnvironment();
        } finally {
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
        }
    }

    /**
     * @param string $agent Executing claimant
     * @param Closure(): void $work Actual write entrance
     */
    protected function asClaimant(string $agent, Closure $work): void
    {
        $this->claim($agent);
        $token = ExecutionContext::push(new ExecutionFrame(agentId: $agent));
        try {
            $work();
        } finally {
            ExecutionContext::pop($token);
        }
    }

    /**
     * Test Remove is deliberate: it proves the partition guard behind the operation ceiling
     * without granting Remove to production channels.
     *
     * @param string $agent Claimant's canonical address
     */
    protected function claim(string $agent): void
    {
        TruthSourceRegistry::register(
            HilosDbContext::notificationDeliveries,
            TruthSourceKeys::all(),
            $agent,
            TruthSourceOperations::of(TruthSourceOperation::Update, TruthSourceOperation::Remove),
        );
        $this->ownershipClaims[] = $agent;
    }

    /**
     * @param Closure(): void $write Real ORM write expected to refuse
     * @param ?string $expectedOwner Expected owner diagnostic when the row has one
     */
    protected function assertRefusedWithoutChanges(Closure $write, ?string $expectedOwner = null): void
    {
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $announced = $this->announcements->count;
        try {
            $write();
            self::fail('The actual write must be refused before SQL or announcements');
        } catch (WriteNotAllowedException $failure) {
            if ($expectedOwner !== null) {
                self::assertStringContainsString($expectedOwner, $failure->getMessage());
            }
            self::assertStringNotContainsString('private-recipient@example.com', $failure->getMessage());
            self::assertStringNotContainsString('Private notification text', $failure->getMessage());
        }
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame($announced, $this->announcements->count);
    }

    /** @return string Address for the email shard owning a zero saved key */
    protected static function mailOwner(): string
    {
        return MailDeliveryChannelAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '1';
    }
}

/** Counts immediate mirror announcements while the real ViewCacheSubscriber remains installed. */
final class DeliveryOwnershipAnnouncements implements SourceMirrorSubscriberInterface
{
    public int $count = 0;

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->count++;
    }
}

final class OwnershipMailAgent extends MailDeliveryChannelAgent
{
    public const ?DbWriterPartition DB_WRITER_PARTITION = DbWriterPartition::NotificationChannelShard;
}

final class OwnershipSmsAgent extends SmsDeliveryChannelAgent
{
    public const ?DbWriterPartition DB_WRITER_PARTITION = DbWriterPartition::NotificationChannelShard;
}

/** A registered worker carrying the enum is still not a delivery claimant. */
final class OwnershipImpostorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'ownership-impostor';
    public const ?DbWriterPartition DB_WRITER_PARTITION = DbWriterPartition::NotificationChannelShard;

    public function onStop(): void
    {
    }
}

abstract class DeliveryOwnershipHilos extends Hilos
{
    public const array AGENTS = [
        OwnershipMailAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => OwnershipMailAgent::class, AgentRegistryKey::INDEXED => true],
        OwnershipSmsAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => OwnershipSmsAgent::class, AgentRegistryKey::INDEXED => true],
        OwnershipImpostorAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => OwnershipImpostorAgent::class],
    ];

    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryOwnershipChannels::class;
}

abstract class DeliveryOwnershipWithoutDescriptorsHilos extends DeliveryOwnershipHilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryChannelRegistry::class;
}

/** A non-delivery worker cannot prove ownership even when its registered route names the right address. */
final class OwnershipRoutedImpostorAgent extends AbstractAgent
{
    public const string AGENT_TYPE = MailDeliveryChannelAgent::AGENT_TYPE;
    public const array AGENT_SIGNALS = MailDeliveryChannelAgent::AGENT_SIGNALS;
    public const ?DbWriterPartition DB_WRITER_PARTITION = DbWriterPartition::NotificationChannelShard;

    public function onStop(): void
    {
    }
}

abstract class DeliveryOwnershipWrongClassHilos extends DeliveryOwnershipHilos
{
    public const array AGENTS = [
        OwnershipRoutedImpostorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OwnershipRoutedImpostorAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

/** Test-only current descriptor width, with no timer, environment override or production flag. */
final class DeliveryOwnershipChannels extends DeliveryChannelRegistry
{
    public static int $width = 2;

    protected static function channels(): array
    {
        return [
            MailDeliveryChannel::NAME => new OwnershipChannel(MailDeliveryChannel::NAME, HilosSignalConstants::HILOS_MAIL_DELIVER),
            SmsDeliveryChannel::NAME => new OwnershipChannel(SmsDeliveryChannel::NAME, HilosSignalConstants::HILOS_SMS_DELIVER),
        ];
    }
}

final class OwnershipChannel extends AbstractDeliveryChannel
{
    public function __construct(private readonly string $channel, private readonly string $signal)
    {
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
        TestCase::fail('The write guard must never look up a recipient address');
    }

    public function isPooled(): bool
    {
        return true;
    }

    public function poolSize(): int
    {
        return DeliveryOwnershipChannels::$width;
    }
}

abstract class DeliveryRawOwnershipHilos extends DeliveryOwnershipHilos
{
    public const array AGENTS = [...parent::AGENTS, SmsRawSendAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => SmsRawSendAgent::class,
        AgentRegistryKey::INDEXED => true,
    ]];
}

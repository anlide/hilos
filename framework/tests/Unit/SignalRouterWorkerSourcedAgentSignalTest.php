<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Push\Delivery\PushDeliveryChannelAgentDaemon;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Sms\DTO\SmsSendSignalData;
use PHPUnit\Framework\TestCase;

/**
 * The seam between the router and the service senders that queue through it (HIL-567).
 *
 * Mail, SMS and the notification dispatcher all queue their agent signal from a worker, not
 * from an agent, and until this ticket the router accepted an agent signal from an agent
 * only — so every one of those signals resolved to no destination and vanished without a
 * word. Both halves were covered on their own and both were green: the senders' own tests
 * hand them a stand-in router, and the router's tests fed it a signal sourced at an agent.
 * The seam is what nobody looked at, so it is what this file holds: the real router, the
 * real signal names, and the source the real senders actually set.
 *
 * The route is asserted by name rather than by driving NotificationDispatcher::dispatch(),
 * which needs a live database. What the name proves is the risk this ticket closes — the
 * name is declared, the route is found — and the rest is left to the live stand run.
 */
final class SignalRouterWorkerSourcedAgentSignalTest extends TestCase
{
    public function testRawMailSendFromAWorkerReachesTheMailPool(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_MAIL_SEND),
            new AgentSignalData(new MailSendSignalData(
                to: 'someone@example.com',
                shardKey: 2,
                subject: 'Verification code',
                text: 'code',
            )),
        ));

        $this->assertEquals([
            new AgentDestination(MailDeliveryChannelAgent::AGENT_TYPE, '2'),
        ], $destinations);
    }

    public function testRawSmsSendFromAWorkerReachesTheSmsPool(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_SMS_SEND),
            new AgentSignalData(new SmsSendSignalData(
                to: '+15550000000',
                shardKey: 3,
                text: 'code',
            )),
        ));

        $this->assertEquals([
            new AgentDestination(SmsDeliveryChannelAgent::AGENT_TYPE, '3'),
        ], $destinations);
    }

    /**
     * The dispatcher does not name an agent: it queues whatever the channel descriptor calls
     * its deliver signal, and the route has to be there for each registered channel.
     */
    public function testEveryChannelDeliverSignalFromAWorkerReachesItsChannelAgent(): void
    {
        $router = new WorkerSourcedAgentSignalTestRouter();

        foreach ([
            new MailDeliveryChannel()->deliverSignalName() => MailDeliveryChannelAgent::AGENT_TYPE,
            new SmsDeliveryChannel()->deliverSignalName() => SmsDeliveryChannelAgent::AGENT_TYPE,
            new PushDeliveryChannel()->deliverSignalName() => PushDeliveryChannelAgent::AGENT_TYPE,
        ] as $signalName => $agentType) {
            $destinations = $router->getDestinations(new SignalDTO(
                new SignalSource(SignalSource::WORKER),
                new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                new SignalName($signalName),
                new AgentSignalData(new NotificationDeliverSignalData(
                    notificationId: 11,
                    channel: 'channel',
                    shardKey: 5,
                )),
            ));

            $this->assertEquals(
                [new AgentDestination($agentType, '5')],
                $destinations,
                "Deliver signal {$signalName} lost its route",
            );
        }
    }

    /**
     * The other branch of agent-signal routing, page-owned instead of agent-owned. It read the
     * same rule from its own copy, so a fix to one of them would have left the other dropping
     * the very same signal.
     */
    public function testPageOwnedAgentSignalFromAWorkerReachesTheOwningPageAgent(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(WorkerSourcedAgentSignalTestPage::PAGE_AGENT_SIGNAL),
            new AgentSignalData(new WorkerSourcedAgentSignalTestPayload()),
        ));

        $this->assertEquals([
            new AgentDestination(WorkerSourcedAgentSignalTestPage::SUBSCRIPTION_AGENT_TYPE),
        ], $destinations);
    }

    /**
     * Accepting the worker widens who may queue an agent signal, not what may be routed: the
     * route is still picked by name from the declared registry, and an undeclared name has
     * nowhere to go whoever sent it.
     */
    public function testUndeclaredSignalNameFromAWorkerStillHasNoDestination(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('hilos_undeclared_send'),
            new AgentSignalData(new WorkerSourcedAgentSignalTestPayload()),
        ));

        $this->assertSame([], $destinations);
    }

    public function testAgentSourcedAgentSignalStillReachesItsAgent(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(HilosSignalConstants::HILOS_MAIL_SEND),
            new AgentSignalData(new MailSendSignalData(
                to: 'someone@example.com',
                shardKey: 2,
                subject: 'Verification code',
                text: 'code',
            )),
        ));

        $this->assertEquals([
            new AgentDestination(MailDeliveryChannelAgent::AGENT_TYPE, '2'),
        ], $destinations);
    }

    /**
     * Cron and binary frames keep one source each: there the source picks the transport the
     * signal may arrive over, so widening the agent signal must not have widened them too.
     */
    public function testCronFromAWorkerHasNoDestination(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::CRON),
            new SignalName(WorkerSourcedAgentSignalTestPage::PAGE_CRON),
            new AgentSignalData(new WorkerSourcedAgentSignalTestPayload()),
        ));

        $this->assertSame([], $destinations);
    }

    public function testBinaryFrameFromAWorkerHasNoDestination(): void
    {
        $destinations = new WorkerSourcedAgentSignalTestRouter()->getDestinations(new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName(SignalTypeConstants::FRAME_BINARY),
            new AgentSignalData(new WorkerSourcedAgentSignalTestPayload()),
        ));

        $this->assertSame([], $destinations);
    }
}

final class WorkerSourcedAgentSignalTestRouter extends SignalRouter
{
    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return WorkerSourcedAgentSignalTestHilos::class;
    }
}

final class WorkerSourcedAgentSignalTestPage extends AbstractPage
{
    public const string PAGE = 'worker_sourced_signal_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'worker_sourced_signal_agent';

    public const string PAGE_AGENT_SIGNAL = 'worker_sourced_page_agent_signal';

    public const string PAGE_CRON = 'worker_sourced_page_cron';

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            self::PAGE_AGENT_SIGNAL,
        ],
        SignalTypeConstants::CRON => [
            self::PAGE_CRON,
        ],
        SignalTypeConstants::FRAME_BINARY => [],
    ];
}

final class WorkerSourcedAgentSignalTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for worker-sourced agent signal tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Fixture topology carrying the real delivery-channel agents, so the routes under test are
 * the ones the framework itself declares rather than a restatement of them.
 */
final class WorkerSourcedAgentSignalTestHilos extends HilosFacade
{
    public const array PAGES = [
        WorkerSourcedAgentSignalTestPage::PAGE => WorkerSourcedAgentSignalTestPage::class,
    ];

    public const array AGENTS = [
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
        ],
        SmsDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SmsDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => SmsDeliveryChannelAgentDaemon::class,
        ],
        PushDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PushDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => PushDeliveryChannelAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new WorkerSourcedAgentSignalTestDbContext();
    }
}

/**
 * Inner payload for the cases whose route does not read a field from it.
 */
final class WorkerSourcedAgentSignalTestPayload implements SignalDataInterface
{
    /**
     * @return array<string, mixed> Empty wire payload
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}

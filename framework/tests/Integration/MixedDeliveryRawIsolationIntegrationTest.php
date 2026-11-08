<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\DTO\MailSendSignalData;
use Hilos\Mail\EmailMessage;
use Hilos\Mail\Exception\MailResultUnavailableException;
use Hilos\Mail\HilosMailer;
use Hilos\Mail\MailSendOutcome;
use Hilos\Mail\MailTransportConfig;
use Hilos\Mail\MailTransportInterface;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsSendAttempt;
use Hilos\Sms\DTO\SmsSendSignalData;
use Hilos\Sms\HilosSmsSender;
use Hilos\Sms\SmsChannelConfig;
use Hilos\Sms\SmsMessage;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

/** Owns topology and env setup outside the managed rollback scope. */
abstract class MixedDeliveryRawIsolationIntegrationTestCase extends NotificationDeliveryApiIntegrationTestCase
{
    private string $previousApp;
    /** @var array<string, string|false> Process values restored after the case */
    private array $previousEnvValues = [];
    protected MixedIsolationMailAgent|MixedIsolationSmsAgent|null $agent = null;

    /** Mounts real topology and valid raw config independently of the durable descriptor's width. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousApp = Hilos::appClass();
        parent::prepareIntegrationEnvironment();
        foreach ([
            [EnvConstants::MAIL_WORKER_COUNT, '1'],
            [EnvConstants::SMS_WORKER_COUNT, '1'],
            [EnvConstants::MAIL_FROM_ADDRESS, 'sender@example.test'],
        ] as [$key, $value]) {
            $this->previousEnvValues[$key->name] = getenv($key->name);
            putenv($key->name . '=' . $value);
        }
        Hilos::$sr = new SignalRouter();
        MixedIsolationHilos::initBrowser();
        MixedIsolationChannels::$width = 1;
        MixedIsolationChannels::$failure = null;
    }

    /** Stops real local work and returns claims, topology and process env after rollback. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->agent !== null) {
                try {
                    $this->agent->onStop();
                } finally {
                    TruthSourceRegistry::unregisterAgent($this->agent->getId());
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->agent->getId()));
                }
            }
        } finally {
            foreach ($this->previousEnvValues as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            MixedIsolationChannels::$width = 1;
            MixedIsolationChannels::$failure = null;
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            parent::releaseIntegrationEnvironment();
        }
    }
}

/** Real mixed ticks isolate raw work from a registered durable descriptor's invalid ownership configuration. */
#[IntegrationTest]
final class MixedDeliveryRawIsolationIntegrationTest extends MixedDeliveryRawIsolationIntegrationTestCase
{
    private const string TICKET = 'a1b2c3d4e5f60718';

    /** @return iterable<string, array{string, bool, string}> Both mixed workers, both config families and raw transport outcomes */
    public static function scenarios(): iterable
    {
        foreach ([MailDeliveryChannel::NAME, SmsDeliveryChannel::NAME] as $channel) {
            foreach ([false, true] as $unreadable) {
                foreach (['success', 'retry', 'terminal'] as $outcome) {
                    yield $channel . ($unreadable ? ' env ' : ' width ') . $outcome => [$channel, $unreadable, $outcome];
                }
            }
        }
    }

    /**
     * @param string $channel Actual mixed worker to exercise
     * @param bool $unreadable Whether the descriptor throws the original environment exception
     * @param string $outcome Scripted first raw attempt's result
     */
    #[DataProvider('scenarios')]
    public function testRawQueuedAndHeldWorkContinuesWhileDurableOwnershipFails(
        string $channel,
        bool $unreadable,
        string $outcome,
    ): void {
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1893, 'Recipient')");
        Database::sql(
            'INSERT INTO hilos_notification (id, user_id, type, title) VALUES (?, ?, ?, ?)',
            [11, 1893, 'test.notification', 'Durable notification'],
        );
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, $channel, 0)->id;
        $before = Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows();
        $raw = new MixedIsolationTransport(match ($outcome) {
            'success' => MailSendOutcome::delivered(),
            'retry' => MailSendOutcome::failed('temporary transport refusal', false),
            'terminal' => MailSendOutcome::failed('recipient rejected', true),
        });
        $retry = new MixedIsolationTransport(MailSendOutcome::delivered());
        $durable = new MixedIsolationTransport(MailSendOutcome::delivered());
        $transports = $outcome === 'retry' ? [$raw, $retry, $durable] : [$raw, $durable];
        $this->agent = $channel === MailDeliveryChannel::NAME
            ? new MixedIsolationMailAgent($transports) : new MixedIsolationSmsAgent($transports);
        OwnershipDeclaration::claimAll($this->agent);
        ExecutionContext::run(new ExecutionFrame(agentId: $this->agent->getId()), function () use (
            $channel, $unreadable, $outcome, $id, $before, $raw, $retry, $durable,
        ): void {
            $this->agent->onStart();
            MixedIsolationChannels::$width = 0;
            MixedIsolationChannels::$failure = $unreadable ? new EnvException('durable pool configuration unavailable') : null;

            if ($channel === MailDeliveryChannel::NAME) {
                self::assertSame(1, HilosMailer::shardKeyForAddress('recipient@example.test'));
                self::assertSame('sender@example.test', MailTransportConfig::fromEnv()->fromAddress);
                $payload = new MailSendSignalData(
                    to: 'recipient@example.test', shardKey: 1, subject: 'Raw code', text: 'Raw code', progressTicket: self::TICKET,
                );
                $name = HilosSignalConstants::HILOS_MAIL_SEND;
            } else {
                self::assertSame(1, HilosSmsSender::shardKeyForNumber('+15551234567'));
                self::assertGreaterThan(0, SmsChannelConfig::resolve(new SmsDeliveryChannel())->maxLength);
                $payload = new SmsSendSignalData(to: '+15551234567', shardKey: 1, text: 'Raw code', progressTicket: self::TICKET);
                $name = HilosSignalConstants::HILOS_SMS_SEND;
            }
            $this->agent->onSignalAgent(new AgentSignalData($payload), 'test', $name);
            self::assertNull($raw->message);

            $this->assertPoolFailure($this->agent->onTick(...), $unreadable);
            self::assertSame('Raw code', $raw->message?->text);
            self::assertSame(0, $raw->ticks);
            self::assertFalse($raw->closed);
            self::assertNull($durable->message);
            self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());
            self::assertSame([HilosCodeSendAttempt::STATE_SENDING], $this->progressStates());

            $this->assertPoolFailure($this->agent->onTick(...), $unreadable);
            self::assertSame(1, $raw->ticks);
            self::assertTrue($raw->closed);
            if ($outcome === 'retry') {
                self::assertSame($raw->message, $retry->message);
                self::assertSame(0, $retry->ticks);
                self::assertSame([HilosCodeSendAttempt::STATE_QUEUED, HilosCodeSendAttempt::STATE_SENDING], $this->progressStates());
                $this->assertPoolFailure($this->agent->onTick(...), $unreadable);
                self::assertSame(1, $retry->ticks);
                self::assertTrue($retry->closed);
            }
            self::assertSame(
                [$outcome === 'terminal' ? HilosCodeSendAttempt::STATE_FAILED : HilosCodeSendAttempt::STATE_SENT],
                $this->progressStates(),
            );
            self::assertNull($durable->message);
            self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery WHERE id = ?', [$id])->rows());

            MixedIsolationChannels::$width = 1;
            MixedIsolationChannels::$failure = null;
            $this->agent->onTick();
            self::assertSame('Durable notification', $durable->message instanceof EmailMessage
                ? $durable->message->subject : $durable->message?->text);
            self::assertSame([['status' => DeliveryStatus::PENDING, 'attempts' => '1']], Database::sql(
                'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$id],
            )->rows());
            $this->agent->onTick();
            $this->agent->onTick();
            self::assertSame([['status' => DeliveryStatus::SENT, 'attempts' => '1']], Database::sql(
                'SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$id],
            )->rows());
            self::assertSame(1, $raw->starts);
            self::assertSame($outcome === 'retry' ? 1 : 0, $retry->starts);
            self::assertSame(1, $durable->starts);
            self::assertSame([], $this->progressStates());
        });
    }

    /**
     * @param Closure(): void $tick Real mixed worker tick
     * @param bool $unreadable Expected config failure family
     */
    private function assertPoolFailure(Closure $tick, bool $unreadable): void
    {
        try {
            $tick();
        } catch (EnvException | InvalidArgumentException $failure) {
            if ($unreadable) {
                self::assertSame(MixedIsolationChannels::$failure, $failure);
            } else {
                self::assertInstanceOf(InvalidArgumentException::class, $failure);
                self::assertStringContainsString('positive', $failure->getMessage());
            }

            return;
        }
        self::fail('Durable ownership configuration failure must reach the caller');
    }

    /** @return list<?string> Watched raw-send progress drained from the real signal queue */
    private function progressStates(): array
    {
        $states = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof CodeSendStepSignalData) {
                self::assertSame(HilosSignalConstants::HILOS_CODE_SEND_STEP, $signal->signalName->getName());
                $states[] = $signal->data->data->state;
            }
        }

        return $states;
    }
}

/** Scripted transport shared by the mail adapter and SMS attempt seams; no lifecycle or owner check is replaced. */
final class MixedIsolationTransport implements MailTransportInterface, SmsSendAttempt
{
    public EmailMessage|SmsMessage|null $message = null;
    public int $starts = 0;
    public int $ticks = 0;
    public bool $closed = false;
    private bool $consumed = false;

    /** @param MailSendOutcome $outcome Result made available on the first deterministic tick */
    public function __construct(private readonly MailSendOutcome $outcome)
    {
    }

    /**
     * @param EmailMessage|SmsMessage $message Message handed to the scripted transport
     * @param float $nowMs Start time, unused by the script
     */
    public function start(EmailMessage|SmsMessage $message, float $nowMs): void
    {
        $this->message = $message;
        $this->starts++;
    }

    /** @param float $nowMs Tick time, unused by the script */
    public function tick(float $nowMs): void
    {
        $this->ticks++;
    }

    /** @return bool Whether the started transport still needs its first tick */
    public function isBusy(): bool
    {
        return $this->message !== null && $this->ticks === 0 && !$this->closed;
    }

    /** @return bool Whether the mail adapter can consume its outcome */
    public function hasResult(): bool
    {
        return $this->ticks > 0 && !$this->consumed;
    }

    /** @return MailSendOutcome Scripted mail outcome consumed once */
    public function consumeResult(): MailSendOutcome
    {
        if (!$this->hasResult()) {
            throw new MailResultUnavailableException('No scripted result');
        }
        $this->consumed = true;

        return $this->outcome;
    }

    /** @return bool Whether the scripted SMS result is delivered */
    public function isDelivered(): bool
    {
        return $this->outcome->delivered;
    }

    /** @return ?string Scripted refusal detail */
    public function errorDetail(): ?string
    {
        return $this->outcome->errorDetail;
    }

    /** @return bool Whether the scripted refusal is terminal */
    public function isPermanentFailure(): bool
    {
        return $this->outcome->permanent;
    }

    /** Records release of this transport. */
    public function close(): void
    {
        $this->closed = true;
    }
}

final class MixedIsolationMailAgent extends MailDeliveryChannelAgent
{
    /** @param list<MixedIsolationTransport> $transports Raw attempts, then the recovered durable attempt */
    public function __construct(private array $transports)
    {
        parent::__construct('1');
    }

    /** @return AbstractDeliveryChannel Real mail descriptor with a fixed fixture recipient */
    protected function channel(): AbstractDeliveryChannel
    {
        return new MixedIsolationMailChannel();
    }

    /** @return MailTransportInterface Next scripted transport */
    protected function createTransport(): MailTransportInterface
    {
        return array_shift($this->transports) ?? throw new InvalidArgumentException('Unexpected extra mail attempt');
    }

    /**
     * @param int $attempts Prior raw attempts
     * @return float No clock wait for the scripted retry
     */
    protected function rawBackoffMs(int $attempts): float
    {
        return 0.0;
    }
}

final class MixedIsolationSmsAgent extends SmsDeliveryChannelAgent
{
    /** @param list<MixedIsolationTransport> $transports Raw attempts, then the recovered durable attempt */
    public function __construct(private array $transports)
    {
        parent::__construct('1');
    }

    /** @return AbstractDeliveryChannel Real SMS descriptor with a fixed fixture recipient */
    protected function channel(): AbstractDeliveryChannel
    {
        return new MixedIsolationSmsChannel();
    }

    /**
     * @param SmsMessage $message Rendered raw or durable SMS
     * @param float $nowMs Start time passed to the script
     * @return SmsSendAttempt Next started scripted transport
     */
    protected function buildAttempt(SmsMessage $message, float $nowMs): SmsSendAttempt
    {
        $transport = array_shift($this->transports) ?? throw new InvalidArgumentException('Unexpected extra SMS attempt');
        $transport->start($message, $nowMs);

        return $transport;
    }

    /**
     * @param int $attempts Prior raw attempts
     * @return float No clock wait for the scripted retry
     */
    protected function rawBackoffMs(int $attempts): float
    {
        return 0.0;
    }
}

final class MixedIsolationChannels extends DeliveryChannelRegistry
{
    public static int $width = 1;
    public static ?EnvException $failure = null;

    /** @return array<string, AbstractDeliveryChannel> Registered project descriptors, independent of raw env pool sizing */
    protected static function channels(): array
    {
        return [MailDeliveryChannel::NAME => new MixedIsolationMailChannel(), SmsDeliveryChannel::NAME => new MixedIsolationSmsChannel()];
    }
}

final class MixedIsolationMailChannel extends MailDeliveryChannel
{
    /**
     * @param int $userId Fixture recipient id
     * @return ?string Fixture email without an unrelated identity-table dependency
     */
    public function resolveAddress(int $userId): ?string
    {
        return 'recipient@example.test';
    }

    /** @return int Durable descriptor width, or the exact scripted environment exception */
    public function poolSize(): int
    {
        return MixedIsolationChannels::$failure === null
            ? MixedIsolationChannels::$width : throw MixedIsolationChannels::$failure;
    }
}

final class MixedIsolationSmsChannel extends SmsDeliveryChannel
{
    /**
     * @param int $userId Fixture recipient id
     * @return ?string Fixture number without an unrelated identity-table dependency
     */
    public function resolveAddress(int $userId): ?string
    {
        return '+15551234567';
    }

    /** @return int Durable descriptor width, or the exact scripted environment exception */
    public function poolSize(): int
    {
        return MixedIsolationChannels::$failure === null
            ? MixedIsolationChannels::$width : throw MixedIsolationChannels::$failure;
    }
}

abstract class MixedIsolationHilos extends Hilos
{
    public const array AGENTS = [
        MixedIsolationMailAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => MixedIsolationMailAgent::class, AgentRegistryKey::INDEXED => true],
        MixedIsolationSmsAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => MixedIsolationSmsAgent::class, AgentRegistryKey::INDEXED => true],
    ];

    protected const string NOTIFICATION_CHANNEL_REGISTRY = MixedIsolationChannels::class;
}

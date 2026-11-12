<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Notification\DTO\DeliveryRetrySignalData;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Adds a facade with routed retry names and the delivery journal on to the retry owner environment before rollback starts. */
abstract class DeliveryRetryLibraryIntegrationTestCase extends DeliveryRetryOwnerIntegrationTestCase
{
    private ?SettingsAccessor $previousSetting = null;

    /** Mounts a facade whose descriptors carry the real retry names, with the delivery journal and its retention on. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousSetting = Hilos::$setting;
        parent::prepareIntegrationEnvironment();
        DeliveryRetryLibraryHilos::initBrowser();
        Hilos::$setting = new SettingsAccessor(DeliveryRetryLibrarySettingsCatalog::class);
    }

    /** Restores the settings accessor beside the facade, claims and router the harness restores. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            parent::releaseIntegrationEnvironment();
        } finally {
            Hilos::$setting = $this->previousSetting;
        }
    }
}

/**
 * The admin retry crosses both of its hops for real: the notifications library names the row's owner
 * and only addresses it, the email shard resets the row and answers the page (HIL-1910).
 *
 * The library receives the page's request under its own agent with the admin as the origin, the
 * way its worker receives an ask; whatever it queues is then handed on to the shard it names, the
 * same way. Each request is answered exactly once on the whole path - by the library when it cannot
 * pass the request on, by the owner otherwise. The rows are read back by an independent SELECT and the
 * journal is followed through the announcements the reset publishes. Old rows a retention pass may
 * select are inserted fresh by SQL, which no process has cached, so no cold reset is owed.
 */
#[IntegrationTest]
final class DeliveryRetryOwnerLibraryIntegrationTest extends DeliveryRetryLibraryIntegrationTestCase
{
    private const string UNAVAILABLE = 'Delivery channel is unavailable';

    /**
     * Two requests for one row each get one answer: the first is taken back, the second finds the row pending.
     *
     * The library passes both on without judging them; the owner resets once under the first asker's
     * origin, refuses the second without a write, and its next tick drives the taken-back row on.
     */
    public function testRepeatedRequestsAreEachAnsweredOnceAcrossBothHops(): void
    {
        $id = $this->failedDelivery();
        $owner = new RetryOwnerMailAgent('1');
        $this->changes->list = [];

        $first = $this->retryRequest($id, 'retry-request-first');
        $firstAsk = $this->passedOn($this->askLibrary($first), $first, $id);
        $this->deliverRetry($owner, $firstAsk);
        $this->assertAnsweredOnce($firstAsk, null);
        self::assertSame([$id], self::queued($owner));
        self::assertSame([[DeliveryStatus::PENDING, '0', null]], $this->savedRow($id));
        self::assertCount(1, $this->changes->list);
        self::assertSame([self::ACCEPT_KEY, 'retry-request-first'], [$this->changes->list[0]->origin, $this->changes->list[0]->originRequestId]);
        $this->assertJournal($this->changes->list[0], $id, DeliveryStatus::PENDING, 0, null);

        $second = $this->retryRequest($id, 'retry-request-second');
        $secondAsk = $this->passedOn($this->askLibrary($second), $second, $id);
        $this->deliverRetry($owner, $secondAsk);
        $this->assertAnsweredOnce($secondAsk, 'Only failed deliveries can be retried');
        self::assertSame([[DeliveryStatus::PENDING, '0', null]], $this->savedRow($id));
        self::assertCount(1, $this->changes->list, 'The refused request writes nothing');

        $owner->script = [new SettledRetryAttempt('mailbox full')];
        $this->tick($owner);
        self::assertSame([[DeliveryStatus::PENDING, '1', null]], $this->savedRow($id));
        self::assertCount(2, $this->changes->list);
        $this->assertJournal($this->changes->list[1], $id, DeliveryStatus::PENDING, 1, null);
        $this->tick($owner);
        self::assertSame([[DeliveryStatus::FAILED, '1', 'mailbox full']], $this->savedRow($id));
        self::assertCount(3, $this->changes->list);
        $this->assertJournal($this->changes->list[2], $id, DeliveryStatus::FAILED, 1, 'mailbox full');
        self::assertSame([], self::agentFrames(), 'Nothing answers either request a second time');
    }

    /**
     * Retention removes the row first: the retry is refused by the library, nothing comes back, and a
     * newer delivery of the same notification and channel is not the one retried.
     */
    public function testRetentionBeforeRetryLeavesTheRowGoneAndTheNewerPairUntouched(): void
    {
        $owner = new RetryOwnerMailAgent('1');
        $expired = $this->insertExpiredDelivery(DeliveryStatus::FAILED);
        $this->startRetentionPass($owner);
        self::assertSame([], $this->savedRow($expired));
        $newer = $this->failedDelivery();
        $newerRow = $this->savedRow($newer);
        $this->changes->list = [];

        $retry = $this->retryRequest($expired);
        $this->assertLibraryAnsweredOnce($this->askLibrary($retry), $retry, "Unknown delivery: {$expired}");

        self::assertSame([], $this->savedRow($expired));
        self::assertSame($newerRow, $this->savedRow($newer));
        self::assertSame([], $this->changes->list);
        self::assertSame([], self::queued($owner));
    }

    /**
     * A retry that lands after the pass selected its page but before it reached the row leaves the row
     * pending, and the pass skips it.
     */
    public function testRetryBeforeRetentionKeepsARowOfAnAlreadySelectedPage(): void
    {
        $owner = new RetryOwnerMailAgent('1');
        $first = $this->insertExpiredDelivery(DeliveryStatus::SENT);
        $retried = $this->insertExpiredDelivery(DeliveryStatus::FAILED);
        $retry = $this->retryRequest($retried);
        $answers = [];
        SourceChangeBus::subscribe(new DeliveryRetryOnDeleteMirror(
            (string)$first,
            function () use ($retry, $retried, $owner, &$answers): void {
                $ask = $this->passedOn($this->askLibrary($retry), $retry, $retried);
                $this->deliverRetry($owner, $ask);
                $answers = self::agentFrames();
            },
        ));
        $this->changes->list = [];

        $this->startRetentionPass($owner);

        self::assertSame(
            [['id' => (string)$retried, 'status' => DeliveryStatus::PENDING, 'attempts' => '0', 'last_error' => null]],
            Database::sql('SELECT id, status, attempts, last_error FROM hilos_notification_delivery ORDER BY id')->rows(),
        );
        self::assertCount(1, $answers);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answers[0][2]);
        self::assertSame([HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE, MailDeliveryChannelAgent::AGENT_TYPE, $retry->requestId, null],
            [$answers[0][0], $answers[0][1], $answers[0][2]->requestId, $answers[0][2]->error]);
        self::assertSame([$retried], self::queued($owner));
        self::assertSame([TableMutationType::Delete, TableMutationType::Update], array_map(
            static fn (SourceChange $change): TableMutationType => $change->mutationType, $this->changes->list,
        ));
        self::assertSame([(string)$retried, self::ACCEPT_KEY, self::REQUEST_ID],
            [$this->changes->list[1]->sourceId, $this->changes->list[1]->origin, $this->changes->list[1]->originRequestId]);
        self::assertSame([], self::agentFrames());
    }

    /** @return iterable<string, array{class-string<Hilos>, string}> Topologies in which the saved row has no owner to take it */
    public static function ownerlessRows(): iterable
    {
        yield 'no descriptor for the saved channel' => [DeliveryOwnershipWithoutDescriptorsHilos::class, MailDeliveryChannel::NAME];
        yield 'no route for the channel retry' => [DeliveryOwnershipHilos::class, MailDeliveryChannel::NAME];
        yield 'no descriptor beside the known ones' => [DeliveryRetryMailOnlyHilos::class, SmsDeliveryChannel::NAME];
    }

    /**
     * A saved row whose channel has no descriptor or no retry route is refused by the library alone, unchanged.
     *
     * @param class-string<Hilos> $topology Facade mounted after the row was failed by its owner
     * @param string $channel Channel the row is saved on
     */
    #[DataProvider('ownerlessRows')]
    public function testRowWithoutADescriptorOrRouteIsRefusedByTheLibraryWithoutChanges(string $topology, string $channel): void
    {
        $id = $channel === MailDeliveryChannel::NAME
            ? $this->failedDelivery()
            : $this->failedDelivery($channel, owner: OwnershipSmsAgent::AGENT_TYPE . AgentConstants::ID_SEPARATOR . '1');
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $this->changes->list = [];
        $topology::initBrowser();

        $retry = $this->retryRequest($id);
        $this->assertLibraryAnsweredOnce($this->askLibrary($retry), $retry, self::UNAVAILABLE);

        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame([], $this->changes->list);
    }

    /**
     * @param int $id Delivery the admin picked
     * @param ?string $requestId Request id of a tracked submit
     * @return DeliveryRetrySignalData The page's request to the library
     */
    private function retryRequest(int $id, ?string $requestId = self::REQUEST_ID): DeliveryRetrySignalData
    {
        return new DeliveryRetrySignalData(
            deliveryId: $id,
            replySignal: HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE,
            acceptKey: self::ACCEPT_KEY,
            requestId: $requestId,
            action: HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY,
            successMessage: null,
        );
    }

    /**
     * Runs the library's handler as its worker does on receipt of the page's ask: its own frame, the admin as origin.
     *
     * @param DeliveryRetrySignalData $retry The page's request
     * @return list<array{string, string, SignalDataInterface}> Every agent frame the library queued
     */
    private function askLibrary(DeliveryRetrySignalData $retry): array
    {
        $library = new DeliveryRetryTestLibrary();
        ExecutionContext::run(new ExecutionFrame(agentId: $library->getId()), static fn () => ExecutionContext::withOrigin(
            $retry->acceptKey,
            $retry->requestId,
            static fn () => $library->onSignalAgent(new AgentSignalData($retry), '', HilosSignalConstants::HILOS_DELIVERY_RETRY),
        ));

        return self::agentFrames();
    }

    /**
     * @param list<array{string, string, SignalDataInterface}> $frames What the library queued
     * @param DeliveryRetrySignalData $retry The page's request
     * @param int $id Delivery the request names
     * @return NotificationDeliveryRetrySignalData The one ask, addressed to the owner by the saved coordinates
     */
    private function passedOn(array $frames, DeliveryRetrySignalData $retry, int $id): NotificationDeliveryRetrySignalData
    {
        self::assertCount(1, $frames, 'The library passes the request on and answers nothing itself');
        [$name, $sender, $ask] = $frames[0];
        self::assertSame([HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY, HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY], [$name, $sender]);
        self::assertInstanceOf(NotificationDeliveryRetrySignalData::class, $ask);
        $saved = Database::sql(
            'SELECT notification_id, channel, routing_key FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->firstRow();
        self::assertSame(
            [$id, (int)$saved['notification_id'], $saved['channel'], (int)$saved['routing_key'], 1],
            [$ask->deliveryId, $ask->notificationId, $ask->channel, $ask->routingKey, $ask->shardKey],
        );
        self::assertSame(
            [$retry->replySignal, $retry->acceptKey, $retry->requestId, $retry->action, $retry->successMessage],
            [$ask->replySignal, $ask->acceptKey, $ask->requestId, $ask->action, $ask->successMessage],
        );

        return $ask;
    }

    /**
     * @param list<array{string, string, SignalDataInterface}> $frames What the library queued
     * @param DeliveryRetrySignalData $retry The page's request
     * @param string $error Refusal the page must read
     */
    private function assertLibraryAnsweredOnce(array $frames, DeliveryRetrySignalData $retry, string $error): void
    {
        self::assertCount(1, $frames, 'A request the library cannot pass on is answered by it once, and asked of nobody');
        [$name, $sender, $answer] = $frames[0];
        self::assertSame([$retry->replySignal, HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY], [$name, $sender]);
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answer);
        self::assertSame(
            [$retry->acceptKey, $retry->requestId, $retry->action, $retry->successMessage, $error, null, null],
            [$answer->acceptKey, $answer->requestId, $answer->action, $answer->successMessage, $answer->error,
                $answer->errorType, $answer->errorDetail],
        );
    }

    /** @param AbstractDeliveryChannelAgent $owner Shard whose retention rule starts and runs one pass */
    private function startRetentionPass(AbstractDeliveryChannelAgent $owner): void
    {
        $this->asClaimant($owner->getId(), static function () use ($owner): void {
            $owner->onStart();
            self::assertNotNull($owner->armCronRuleForTest('hilos_delivery_log_prune'));
            $owner->onTick();
        });
    }

    /**
     * @param string $status Terminal status of a row old enough for any retention window
     * @return int The inserted email delivery of shard 1, created at the first millisecond of the epoch
     */
    private function insertExpiredDelivery(string $status): int
    {
        Database::sql(
            'INSERT INTO hilos_notification_delivery (notification_id, channel, routing_key, status, attempts, last_error, created_at, updated_at)'
            . ' VALUES (?, ?, 0, ?, 1, ?, 1, 1)',
            [$this->notificationId, MailDeliveryChannel::NAME, $status, $status === DeliveryStatus::FAILED ? 'smtp down' : null],
        );

        return (int)Database::sql('SELECT MAX(id) AS id FROM hilos_notification_delivery')->firstRow()['id'];
    }

    /**
     * @param int $id Delivery primary key
     * @return list<list<?string>> Status, attempts and last error, read past the ORM
     */
    private function savedRow(int $id): array
    {
        return array_map('array_values', Database::sql(
            'SELECT status, attempts, last_error FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    /**
     * @param SourceChange $change Announcement the delivery journal hears live
     * @param int $id Delivery the journal row stands for
     * @param string $status Status the journal row must show
     * @param int $attempts Attempts the journal row must show
     * @param ?string $error Last error the journal row must show
     */
    private function assertJournal(SourceChange $change, int $id, string $status, int $attempts, ?string $error): void
    {
        $mutation = new HilosNotificationDeliveriesTable()->buildMutationForSourceEvent($change);
        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        $row = $mutation->row;
        self::assertInstanceOf(HilosNotificationDeliveryTableRow::class, $row);
        self::assertSame([$id, $status, $attempts, $error], [$row->rowKey, $row->status, $row->attempts, $row->lastError]);
    }
}

/** The production library, mounted under its own agent type with nothing of a project's added. */
final class DeliveryRetryTestLibrary extends AbstractNotificationsLibraryAgent
{
}

/** The ownership topology with descriptors that name the real retry signals, and the delivery journal on. */
abstract class DeliveryRetryLibraryHilos extends DeliveryOwnershipHilos
{
    protected const array FEATURES = [HilosFeature::NOTIFICATION_DELIVERY];
    protected const string SETTINGS_CATALOG = DeliveryRetryLibrarySettingsCatalog::class;
    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryRetryLibraryChannels::class;
}

/** The same topology with only the email descriptor: a saved text message has no channel left to own it. */
abstract class DeliveryRetryMailOnlyHilos extends DeliveryRetryLibraryHilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryRetryMailOnlyChannels::class;
}

final class DeliveryRetryLibrarySettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Retention and enablement keys used by the real channel */
    public static function getCatalog(): array
    {
        return array_replace(DeliveryLogSettingsCatalog::getCatalog(), ChannelSettingsCatalog::getCatalog());
    }
}

/** Email and text descriptors at the ownership pool width, under the retry names their agents declare. */
final class DeliveryRetryLibraryChannels extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return [
            MailDeliveryChannel::NAME => new DeliveryRetryRoutedChannel(
                MailDeliveryChannel::NAME, HilosSignalConstants::HILOS_MAIL_DELIVER, HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY,
            ),
            SmsDeliveryChannel::NAME => new DeliveryRetryRoutedChannel(
                SmsDeliveryChannel::NAME, HilosSignalConstants::HILOS_SMS_DELIVER, HilosSignalConstants::HILOS_SMS_DELIVERY_RETRY,
            ),
        ];
    }
}

final class DeliveryRetryMailOnlyChannels extends DeliveryChannelRegistry
{
    protected static function channels(): array
    {
        return [MailDeliveryChannel::NAME => new DeliveryRetryRoutedChannel(
            MailDeliveryChannel::NAME, HilosSignalConstants::HILOS_MAIL_DELIVER, HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY,
        )];
    }
}

final class DeliveryRetryRoutedChannel extends AbstractDeliveryChannel
{
    public function __construct(private readonly string $channel, private readonly string $deliver, private readonly string $retry)
    {
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function deliverSignalName(): string
    {
        return $this->deliver;
    }

    public function retrySignalName(): string
    {
        return $this->retry;
    }

    public function resolveAddress(int $userId): ?string
    {
        TestCase::fail('A retry must never look up a recipient address');
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

/** Runs a test step synchronously at the announcement that one delivery was deleted. */
final class DeliveryRetryOnDeleteMirror implements SourceMirrorSubscriberInterface
{
    private bool $ran = false;

    /**
     * @param string $deliveryId Delivery whose deletion triggers the step
     * @param Closure(): void $step Step to run once, between the pass's selection and its next row
     */
    public function __construct(private readonly string $deliveryId, private readonly Closure $step)
    {
    }

    /**
     * @param SourceChange $change Immediate source fact
     * @param SourceChangeProvenance $provenance Source path, unused
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$this->ran && $change->sourceKey === HilosDbContext::notificationDeliveries
            && $change->sourceId === $this->deliveryId && $change->mutationType === TableMutationType::Delete) {
            $this->ran = true;
            ($this->step)();
        }
    }
}

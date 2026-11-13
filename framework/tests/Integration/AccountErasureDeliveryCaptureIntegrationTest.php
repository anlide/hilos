<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\AccountOperation\AccountErasureCapture;
use Hilos\Auth\AccountOperation\AccountErasureDeliveryCapture;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationExpectedSet;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Users\SecondFactorFate;
use Throwable;

/**
 * The portions of an account's notification deliveries the erasure coordinator hands to the
 * channel owners after the start, against real tables, written as the session holder (HIL-1940).
 *
 * Two pooled channels four senders wide route by the saved key (`1 + key % 4`); `capture_pigeon`
 * deliveries are saved but no descriptor knows the channel, so they have no owner.
 */
final class AccountErasureDeliveryCaptureIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user',
        'hilos_account_deletion',
        'hilos_identity',
        'hilos_user_merge',
        'hilos_user_verification',
        'hilos_notification',
        'hilos_notification_delivery',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
    ];
    private const string COORDINATOR_ID = 'erasure-delivery-capture-coordinator';
    private const int FOLDED_ID = 42;
    private const int ROOT_ID = 43;
    private const int OUTSIDER_ID = 44;

    /** Stage the case moves the erasure on to between two hand-outs: the capture's own stages are HIL-1950's. */
    private const string LATER_STAGE = 'capturing';

    private ?HilosDbContext $previousDb;
    private string $previousApp;
    private int $frameToken;

    /** Raises the tables, mounts a fresh context and acts as the session holder. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousApp = Hilos::appClass();
        $this->stubs(down: true);
        $this->stubs(down: false);
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new ErasureDeliveryCaptureContext();
        Hilos::$db->configure();
        ErasureDeliveryCaptureHilos::initBrowser();
        OwnershipDeclaration::claimDb(ErasureDeliveryCaptureCoordinator::class, self::COORDINATOR_ID);
        $this->frameToken = ExecutionContext::push(new ExecutionFrame(agentId: self::COORDINATOR_ID));
        foreach ([self::FOLDED_ID, self::ROOT_ID, self::OUTSIDER_ID] as $userId) {
            Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Capture person')", [$userId]);
        }
    }

    /** Ends transactions before DDL, releases the claim and returns the previous context and application. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            $this->stubs(down: true);
            Schema::reset();
        } finally {
            $this->previousApp::initBrowser();
            Hilos::resetBrowser();
            ExecutionContext::pop($this->frameToken);
            TruthSourceRegistry::unregisterAgent(self::COORDINATOR_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::COORDINATOR_ID));
            Hilos::$db = $this->previousDb;
            parent::tearDown();
        }
    }

    /**
     * One portion per channel and owner, keyed by its first number, names the notifications whose
     * deliveries are that owner's, ascending; another person's notification is in none.
     */
    public function testPortionsGroupTheNotificationsByChannelAndOwnerInAscendingOrder(): void
    {
        $first = self::seedNotification(self::ROOT_ID);
        $second = self::seedNotification(self::ROOT_ID);
        $third = self::seedNotification(self::ROOT_ID);
        $foreign = self::seedNotification(self::OUTSIDER_ID);
        self::seedDelivery($second, 'capture_letter', 5);
        self::seedDelivery($first, 'capture_letter', 9);
        self::seedDelivery($first, 'capture_letter', 6);
        self::seedDelivery($third, 'capture_text', 7);
        self::seedDelivery($second, 'capture_text', 7);
        self::seedDelivery($foreign, 'capture_letter', 5);
        $operation = self::openStarted(self::ROOT_ID);

        $recorded = AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());

        $portions = [
            [
                "capture:delivery:capture_letter:capture_letter_sender:2:43:{$first}",
                'capture_letter_sender',
                '2',
                self::ROOT_ID,
                "{\"participant\":\"delivery:capture_letter\",\"notificationIds\":[{$first},{$second}]}",
            ],
            [
                "capture:delivery:capture_letter:capture_letter_sender:3:43:{$first}",
                'capture_letter_sender',
                '3',
                self::ROOT_ID,
                "{\"participant\":\"delivery:capture_letter\",\"notificationIds\":[{$first}]}",
            ],
            [
                "capture:delivery:capture_text:capture_text_sender:4:43:{$second}",
                'capture_text_sender',
                '4',
                self::ROOT_ID,
                "{\"participant\":\"delivery:capture_text\",\"notificationIds\":[{$second},{$third}]}",
            ],
        ];
        self::assertSame(
            ['capture:personal:hilos_user:43:43', ...array_column($portions, 0)],
            self::keysOf($recorded),
        );
        self::assertSame($portions, self::storedPortions());
        self::assertSame(
            array_slice(self::idsOf($recorded), 1),
            self::idsOf(AccountErasureDeliveryCapture::capturesOf($operation->id, self::ROOT_ID)),
        );
        self::assertSame([], AccountErasureDeliveryCapture::capturesOf($operation->id, self::OUTSIDER_ID));
        self::assertSame([], AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($operation->id, self::ROOT_ID));
        self::assertSame([$foreign], AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($operation->id, self::OUTSIDER_ID));
    }

    /** A repeat with nothing new records nothing and stores nothing. */
    public function testARepeatRecordsNothing(): void
    {
        $notification = self::seedNotification(self::ROOT_ID);
        self::seedDelivery($notification, 'capture_letter', 5);
        self::seedDelivery($notification, 'capture_text', 7);
        $operation = self::openStarted(self::ROOT_ID);
        self::assertCount(3, AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework()));
        $stored = self::storedAssignments();

        $repeat = AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());

        self::assertSame([], $repeat);
        self::assertSame($stored, self::storedAssignments());
        self::assertFalse(Database::hasOpenTransactions());
    }

    /**
     * A notification created after the capture is uncaptured; the next call, made once the erasure
     * has moved on to a later stage, writes it a portion keyed by its own number and nothing that
     * the earlier stage holds already, and both portions are named across the two stages.
     */
    public function testALateNotificationGetsTheNextPortion(): void
    {
        $early = self::seedNotification(self::ROOT_ID);
        self::seedDelivery($early, 'capture_letter', 5);
        $operation = self::openStarted(self::ROOT_ID);
        AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());
        self::assertTrue($operation->actions->advance($operation->stage, self::LATER_STAGE));
        self::assertSame(self::LATER_STAGE, $operation->stage);
        $late = self::seedNotification(self::ROOT_ID);
        self::seedDelivery($late, 'capture_letter', 5);

        self::assertSame([$late], AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($operation->id, self::ROOT_ID));

        $recorded = AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());

        self::assertSame(["capture:delivery:capture_letter:capture_letter_sender:2:43:{$late}"], self::keysOf($recorded));
        self::assertSame(
            "{\"participant\":\"delivery:capture_letter\",\"notificationIds\":[{$late}]}",
            $recorded[0]->payload->toJson(),
        );
        self::assertSame([], AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($operation->id, self::ROOT_ID));
        self::assertSame(
            [[$early], [$late]],
            array_map(
                static fn (AccountOperationAssignment $capture): array => AccountErasureDeliveryCapture::notificationIdsOf($capture->payload),
                AccountErasureDeliveryCapture::capturesOf($operation->id, self::ROOT_ID),
            ),
        );
    }

    /**
     * Once a notification is deleted, its portions still name it and its deliveries are still
     * found by the saved numbers; nothing is uncaptured and a repeat records nothing.
     */
    public function testADeletedNotificationIsStillNamedAndItsDeliveriesFoundByItsNumber(): void
    {
        $deleted = self::seedNotification(self::ROOT_ID);
        $kept = self::seedNotification(self::ROOT_ID);
        $letter = self::seedDelivery($deleted, 'capture_letter', 5);
        $text = self::seedDelivery($deleted, 'capture_text', 7);
        $keptLetter = self::seedDelivery($kept, 'capture_letter', 5);
        $operation = self::openStarted(self::ROOT_ID);
        AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());

        Database::sqlRun('DELETE FROM `hilos_notification` WHERE `id` = ?', [$deleted]);

        $named = [];
        foreach (AccountErasureDeliveryCapture::capturesOf($operation->id, self::ROOT_ID) as $capture) {
            $named[AccountErasureDeliveryCapture::channelOf($capture->payload)] = AccountErasureDeliveryCapture::notificationIdsOf($capture->payload);
        }
        self::assertSame(['capture_letter' => [$deleted, $kept], 'capture_text' => [$deleted]], $named);
        self::assertSame([$letter, $keptLetter], self::deliveriesNamed('capture_letter', $named['capture_letter']));
        self::assertSame([$text], self::deliveriesNamed('capture_text', $named['capture_text']));
        self::assertSame([], AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($operation->id, self::ROOT_ID));
        self::assertSame([], AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework()));
    }

    /**
     * A delivery on a channel with no owner is not captured: its notification stays uncaptured
     * even when its other delivery is, and the census wait for the channel stays in place.
     */
    public function testADeliveryWithoutAnOwnerStaysUncapturedAndItsWaitStays(): void
    {
        $both = self::seedNotification(self::ROOT_ID);
        $letterOnly = self::seedNotification(self::ROOT_ID);
        self::seedDelivery($both, 'capture_letter', 5);
        self::seedDelivery($both, 'capture_pigeon', 5);
        self::seedDelivery($letterOnly, 'capture_letter', 5);
        $operation = self::openStarted(self::ROOT_ID);

        $recorded = AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());

        self::assertSame(
            ['capture:personal:hilos_user:43:43', "capture:delivery:capture_letter:capture_letter_sender:2:43:{$both}"],
            self::keysOf($recorded),
        );
        self::assertSame(
            "{\"participant\":\"delivery:capture_letter\",\"notificationIds\":[{$both},{$letterOnly}]}",
            $recorded[1]->payload->toJson(),
        );
        self::assertSame([$both], AccountErasureDeliveryCapture::uncapturedNotificationIdsOf($operation->id, self::ROOT_ID));
        self::assertSame(['wait:delivery:capture_pigeon:43'], self::keysOf(AccountOperationExpectedSet::of($operation->id)->waiting()));
        self::assertSame([], AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework()));
    }

    /** A channel first seen after the opening is reconciled into the census and gets its portion in the same call. */
    public function testAChannelFirstSeenAfterTheOpeningIsExpectedAndCaptured(): void
    {
        $notification = self::seedNotification(self::ROOT_ID);
        self::seedDelivery($notification, 'capture_letter', 5);
        $operation = self::openStarted(self::ROOT_ID);
        self::assertNotContains(
            'expect:delivery:capture_text:capture_text_sender:4:43',
            self::keysOf(AccountOperationExpectedSet::of($operation->id)->expected()),
        );
        self::seedDelivery($notification, 'capture_text', 7);

        $recorded = AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework());

        self::assertSame(
            [
                'expect:delivery:capture_text:capture_text_sender:4:43',
                'capture:personal:hilos_user:43:43',
                "capture:delivery:capture_letter:capture_letter_sender:2:43:{$notification}",
                "capture:delivery:capture_text:capture_text_sender:4:43:{$notification}",
            ],
            self::keysOf($recorded),
        );
        self::assertSame('{"participant":"delivery:capture_text"}', $recorded[0]->payload->toJson());
    }

    /** A finished erasure and a merge are refused before anything is reconciled or written. */
    public function testAClosedErasureAndAMergeAreRefused(): void
    {
        self::seedDelivery(self::seedNotification(self::OUTSIDER_ID), 'capture_letter', 5);
        $finished = Hilos::$db->accountOperations->actions->recordErasure(
            801,
            self::OUTSIDER_ID,
            AccountOperationCircle::fromArray([self::OUTSIDER_ID], self::OUTSIDER_ID),
        );
        self::assertTrue($finished->actions->finish($finished->stage, AccountOperationOutcome::NotStarted));
        $merge = Hilos::$db->accountOperations->actions->recordMerge(
            self::FOLDED_ID,
            self::ROOT_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        );
        $stored = self::storedAssignments();

        foreach ([$finished, $merge] as $operation) {
            $refusal = self::refusal(
                static fn () => AccountErasureCapture::recordAfterStart($operation, AccountErasureParticipantCatalog::framework()),
            );
            self::assertInstanceOf(ValidationException::class, $refusal);
            self::assertSame('Only an open erasure captures its anchors.', $refusal->getMessage());
        }
        self::assertSame($stored, self::storedAssignments());
        self::assertFalse(Database::hasOpenTransactions());
    }

    /**
     * Opens the erasure of a deletion request the person's agent has started already.
     *
     * @param int $userId Person asking for the deletion
     * @return AccountOperation Open erasure at the stage the opening left it
     */
    private static function openStarted(int $userId): AccountOperation
    {
        Database::sql(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`, `started_at`)'
            . ' VALUES (?, 1790416800000, 1790416800000, 1790416800000)',
            [$userId],
        );
        $operation = AccountErasureOpening::open(
            Hilos::$db->accountDeletions[Database::lastInsertId()],
            AccountErasureParticipantCatalog::framework(),
        )->operation;
        self::assertNotNull($operation);

        return $operation;
    }

    /**
     * @param int $userId Recipient
     * @return int Notification id
     */
    private static function seedNotification(int $userId): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`, `created_at`) VALUES (?, ?, ?, 1790416800000)',
            [$userId, 'account.capture', 'Capture notification'],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $notificationId Notification delivered
     * @param string $channel Delivery channel
     * @param int $routingKey Saved routing key
     * @return int Delivery id
     */
    private static function seedDelivery(int $notificationId, string $channel, int $routingKey): int
    {
        Database::sql(
            'INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`, `routing_key`, `status`, `created_at`, `updated_at`) '
            . 'VALUES (?, ?, ?, ?, 1790416800000, 1790416800000)',
            [$notificationId, $channel, $routingKey, DeliveryStatus::PENDING],
        );

        return Database::lastInsertId();
    }

    /**
     * @param string $channel Channel of a portion
     * @param list<int> $notificationIds Numbers the portion names
     * @return list<int> Ids of the saved deliveries on that channel of those notifications, ascending
     */
    private static function deliveriesNamed(string $channel, array $notificationIds): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::sql(
                'SELECT `id` FROM `hilos_notification_delivery` WHERE `channel` = ? AND `notification_id` IN ('
                    . implode(', ', array_fill(0, count($notificationIds), '?')) . ') ORDER BY `id`',
                [$channel, ...$notificationIds],
            )->rows(),
        );
    }

    /** @return list<array{string, string, ?string, int, string}> Key, owner, account and payload of every stored portion, in id order */
    private static function storedPortions(): array
    {
        return array_map(
            static fn (array $row): array => [
                (string)$row['assignment_key'],
                (string)$row['owner_type'],
                $row['owner_index'] === null ? null : (string)$row['owner_index'],
                (int)$row['user_id'],
                (string)$row['payload_json'],
            ],
            Database::sql(
                'SELECT `assignment_key`, `owner_type`, `owner_index`, `user_id`, `payload_json` FROM `hilos_account_operation_assignment`'
                    . ' WHERE `assignment_key` LIKE ? ORDER BY `id`',
                ['capture:delivery:%'],
            )->rows(),
        );
    }

    /** @return list<array<string, mixed>> Every stored assignment, in id order */
    private static function storedAssignments(): array
    {
        return Database::sql('SELECT * FROM `hilos_account_operation_assignment` ORDER BY `id`')->rows();
    }

    /**
     * @param Closure(): mixed $attempt Call expected to be refused
     * @return Throwable What refused it
     */
    private static function refusal(Closure $attempt): Throwable
    {
        try {
            $attempt();
        } catch (Throwable $failure) {
            return $failure;
        }
        self::fail('The call was not refused');
    }

    /**
     * @param list<AccountOperationAssignment> $assignments Stored assignments
     * @return list<string> Their keys in order
     */
    private static function keysOf(array $assignments): array
    {
        return array_map(static fn (AccountOperationAssignment $assignment): string => $assignment->assignmentKey, $assignments);
    }

    /**
     * @param list<AccountOperationAssignment> $assignments Stored assignments
     * @return list<?int> Their ids in order
     */
    private static function idsOf(array $assignments): array
    {
        return array_map(static fn (AccountOperationAssignment $assignment): ?int => $assignment->id, $assignments);
    }

    /** @param bool $down Whether to drop in reverse dependency order */
    private function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: up stub filenames carry no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

/** The framework's collections, nothing added. */
final class ErasureDeliveryCaptureContext extends HilosDbContext
{
}

/** The session holder: it carries the erasure coordinator that hands the captures out. */
final class ErasureDeliveryCaptureCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Every owner the framework catalog names, so the only wait is the ownerless channel's, and two pooled channel senders. */
abstract class ErasureDeliveryCaptureHilos extends Hilos
{
    protected const string NOTIFICATION_CHANNEL_REGISTRY = ErasureDeliveryCaptureChannels::class;

    public const array AGENTS = [
        HilosAgentType::HILOS_USER => [AgentRegistryKey::WORKER => ErasureDeliveryCaptureOwnerAgent::class, AgentRegistryKey::INDEXED => true],
        HilosAgentType::HILOS_USERS_LIBRARY => [AgentRegistryKey::WORKER => ErasureDeliveryCaptureOwnerAgent::class],
        HilosAgentType::HILOS_SESSIONS_LIBRARY => [AgentRegistryKey::WORKER => ErasureDeliveryCaptureOwnerAgent::class],
        HilosAgentType::HILOS_DATA_EXPORT => [AgentRegistryKey::WORKER => ErasureDeliveryCaptureOwnerAgent::class],
        HilosAgentType::HILOS_LEGAL => [AgentRegistryKey::WORKER => ErasureDeliveryCaptureOwnerAgent::class],
        ErasureDeliveryCaptureLetterAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ErasureDeliveryCaptureLetterAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
        ErasureDeliveryCaptureTextAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ErasureDeliveryCaptureTextAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}

/** Two pooled channels; `capture_pigeon` deliveries are saved but no descriptor knows the channel. */
abstract class ErasureDeliveryCaptureChannels extends DeliveryChannelRegistry
{
    /** @return array<string, AbstractDeliveryChannel> Letter and text channels by name */
    protected static function channels(): array
    {
        return [
            'capture_letter' => new ErasureDeliveryCaptureChannel('capture_letter', ErasureDeliveryCaptureLetterAgent::SIGNAL),
            'capture_text' => new ErasureDeliveryCaptureChannel('capture_text', ErasureDeliveryCaptureTextAgent::SIGNAL),
        ];
    }
}

/** A pooled channel four senders wide, routed by the saved key alone. */
final class ErasureDeliveryCaptureChannel extends AbstractDeliveryChannel
{
    /**
     * @param string $channel Channel name
     * @param string $signal Delivery signal of its sender
     */
    public function __construct(private readonly string $channel, private readonly string $signal)
    {
    }

    /** @return string Channel name */
    public function name(): string
    {
        return $this->channel;
    }

    /** @return string Delivery signal of its sender */
    public function deliverSignalName(): string
    {
        return $this->signal;
    }

    /** @return string Retry signal of its sender */
    public function retrySignalName(): string
    {
        return $this->signal . '_retry';
    }

    /**
     * @param int $userId Recipient
     * @return ?string No address: the capture never resolves one
     */
    public function resolveAddress(int $userId): ?string
    {
        return null;
    }

    /** @return bool Always pooled */
    public function isPooled(): bool
    {
        return true;
    }

    /** @return int Four senders */
    public function poolSize(): int
    {
        return 4;
    }
}

/** A registered owner with no signals: the census only asks whether its type is registered. */
final class ErasureDeliveryCaptureOwnerAgent extends AbstractAgent
{
    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** The letter channel's indexed sender. */
final class ErasureDeliveryCaptureLetterAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'capture_letter_sender';
    public const string SIGNAL = 'capture_letter_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

/** The text channel's indexed sender. */
final class ErasureDeliveryCaptureTextAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'capture_text_sender';
    public const string SIGNAL = 'capture_text_deliver';
    public const array AGENT_SIGNALS = [
        self::SIGNAL => [AgentSignalConfigKey::INDEX_FIELD => NotificationDeliverSignalData::shardKey],
    ];

    /** Nothing to release. */
    public function onStop(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountErasureDeliveryCapture;
use Hilos\Auth\AccountOperation\AccountErasureDeliveryResult;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationStepRefusal;
use Hilos\Auth\AccountOperation\Exception\AccountOperationStepRefusedException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgent;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Testing\IntegrationTest;
use Hilos\Users\SecondFactorFate;
use PHPUnit\Framework\Attributes\DataProvider;

/** Adds the erasure tables and participant rights outside each managed rollback scope. */
abstract class ErasureDeliveryParticipantIntegrationTestCase extends NotificationDeliveryOwnershipIntegrationTestCase
{
    private const array TABLES = [
        'hilos_identity',
        'hilos_user_merge',
        'hilos_user_verification',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** @var list<string> Extra tables created before the rollback root */
    private array $createdTables = [];
    /** @var list<string> Participant claims and reader interests released with the environment */
    protected array $participantClaims = [];
    private ?SettingsAccessor $previousSetting = null;

    /** Adds operation tables and the real coordinator's rights outside the rollback scope. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousSetting = Hilos::$setting;
        parent::prepareIntegrationEnvironment();
        foreach (self::TABLES as $table) {
            self::stub($table, false);
            $this->createdTables[] = $table;
        }
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(ErasureDeliveryCoordinator::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $this->participantClaims[] = HilosAgentType::HILOS_SESSIONS_LIBRARY;
        Hilos::$setting = new SettingsAccessor(ChannelSettingsCatalog::class);
    }

    /** Removes the added schema and releases every claimed shard even after a failing case. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach (array_reverse($this->createdTables) as $table) {
                self::stub($table, true);
            }
            Schema::reset();
        } finally {
            foreach ($this->participantClaims as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            Hilos::$setting = $this->previousSetting;
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * @param string $table Existing framework migration stub
     * @param bool $down Whether to drop the table
     */
    private static function stub(string $table, bool $down): void
    {
        // external-boundary: up stub filenames carry no direction suffix
        $suffix = $down ? '_down' : '';
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
    }
}

/** The real channel owners erase captured rows through their ordinary partition-guarded item actions. */
#[IntegrationTest]
final class AccountErasureDeliveryParticipantIntegrationTest extends ErasureDeliveryParticipantIntegrationTestCase
{
    private const int USER_ID = 1945;
    private const int NEIGHBOUR_ID = 1946;

    private ErasureDeliveryChanges $changes;

    /** Gives every case an account and a neighbour, and observes immediate per-row source facts. */
    protected function setUpInTransaction(): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_user (id, name) VALUES (?, ?), (?, ?)',
            [self::USER_ID, 'Erasing recipient', self::NEIGHBOUR_ID, 'Neighbour'],
        );
        $this->changes = new ErasureDeliveryChanges();
        SourceChangeBus::subscribe($this->changes);
    }

    /** Own mail rows, including an orphan, leave; the other shard, channel and account keep theirs. */
    public function testOnlyTheCurrentOwnersCapturedRowsAreDeletedAndAnnounced(): void
    {
        $first = self::notification(self::USER_ID);
        $orphan = self::notification(self::USER_ID);
        $mine = $this->delivery($first, MailDeliveryChannel::NAME, 0);
        $orphanDelivery = $this->delivery($orphan, MailDeliveryChannel::NAME, 2);
        $otherShard = $this->delivery($first, MailDeliveryChannel::NAME, 1);
        $sms = $this->delivery($first, SmsDeliveryChannel::NAME, 0);
        $neighbour = $this->delivery(self::notification(self::NEIGHBOUR_ID), MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $assignmentId = $this->deliveryPart($this->openCaptured(), $agent);
        Database::sqlRun('DELETE FROM hilos_notification WHERE id = ?', [$orphan]);

        $result = $this->erase($agent, $assignmentId);

        self::assertSame('{"deliveries":2}', $result->payloadJson);
        self::assertSame(2, AccountErasureDeliveryResult::deliveriesOf($result->payload));
        self::assertSame([$otherShard, $sms, $neighbour], self::deliveryIds());
        self::assertSame([[$assignmentId, $agent->getId(), '{"deliveries":2}']], self::storedResults());
        self::assertSame([(string)$mine, (string)$orphanDelivery], array_map(
            static fn (SourceChange $change): string => $change->sourceId,
            $this->changes->deletions,
        ));
        foreach ($this->changes->deletions as $change) {
            self::assertSame($change->sourceId, (string)$change->row['id']);
        }
    }

    /** The saved result wins over a new row inserted under the very same captured notification number. */
    public function testARepeatReturnsTheSameResultWithoutDeletingAgain(): void
    {
        $notificationId = self::notification(self::USER_ID);
        $this->delivery($notificationId, MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $assignmentId = $this->deliveryPart($this->openCaptured(), $agent);
        $first = $this->erase($agent, $assignmentId);
        $late = $this->delivery($notificationId, MailDeliveryChannel::NAME, 0);

        $repeat = $this->erase($agent, $assignmentId);

        self::assertSame($first->id, $repeat->id);
        self::assertSame('{"deliveries":1}', $repeat->payloadJson);
        self::assertSame([$late], self::deliveryIds());
        self::assertSame([[$assignmentId, $agent->getId(), '{"deliveries":1}']], self::storedResults());
        self::assertCount(1, $this->changes->deletions);
    }

    /** Channel enablement controls sending; a disabled owner's erasure part still records its work. */
    public function testADisabledChannelErasesItsDeliveries(): void
    {
        self::assertFalse(Hilos::$setting[DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME)]->bool());
        $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $assignmentId = $this->deliveryPart($this->openCaptured(), $agent);

        $result = $this->erase($agent, $assignmentId);

        self::assertSame('{"deliveries":1}', $result->payloadJson);
        self::assertSame([], self::deliveryIds());
        self::assertSame([[$assignmentId, $agent->getId(), '{"deliveries":1}']], self::storedResults());
    }

    /** A resized pool uses today's route, including rows captured for the other original shard. */
    public function testAChangedPoolWidthUsesTheCurrentOwnerAcrossAllChannelPortions(): void
    {
        $ids = [];
        for ($key = 0; $key < 6; $key++) {
            $ids[] = $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, $key);
        }
        $operation = $this->openCaptured();
        DeliveryOwnershipChannels::$width = 3;
        $first = $this->mailAgent('1');
        $second = $this->mailAgent('2');
        $firstPart = $this->deliveryPart($operation, $first);
        $secondPart = $this->deliveryPart($operation, $second);

        self::assertSame('{"deliveries":2}', $this->erase($first, $firstPart)->payloadJson);
        self::assertSame([$ids[1], $ids[2], $ids[4], $ids[5]], self::deliveryIds());
        self::assertSame('{"deliveries":2}', $this->erase($second, $secondPart)->payloadJson);
        self::assertSame([$ids[2], $ids[5]], self::deliveryIds());
        self::assertCount(4, $this->changes->deletions);
    }

    /** An uncaptured delivery refuses before any deletion, then the same part succeeds after another capture. */
    public function testALateDeliveryWaitsForItsCaptureBeforeDeletingAnything(): void
    {
        $early = $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $operation = $this->openCaptured();
        $assignmentId = $this->deliveryPart($operation, $agent);
        $late = $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);

        $this->assertPartRefused($agent, $assignmentId, 'A delivery erasure part waits for its deliveries to be captured.');
        self::assertSame([$early, $late], self::deliveryIds());
        $this->asCoordinator(static fn () => AccountErasureDeliveryCapture::recordMissing($operation));

        self::assertSame('{"deliveries":2}', $this->erase($agent, $assignmentId)->payloadJson);
        self::assertSame([], self::deliveryIds());
    }

    /** A merge cannot lend its assignment to an erasure part. */
    public function testAMergeAssignmentIsRefusedBeforeWork(): void
    {
        $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $merge = $this->asCoordinator(static fn (): AccountOperation => Hilos::$db->accountOperations->actions->recordMerge(
            self::NEIGHBOUR_ID,
            self::USER_ID,
            new AccountMergeChoice(PasswordFate::SURVIVOR, SecondFactorFate::SURVIVOR, true, false),
        ));

        $this->assertPartRefused($agent, $this->deliveryPart($merge, $agent), 'A delivery erasure part must belong to an erasure.');
    }

    /** An assignment with no account names nobody whose deliveries may be removed. */
    public function testAnAssignmentWithoutAnAccountIsRefusedBeforeWork(): void
    {
        $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $assignmentId = $this->deliveryPart($this->openCaptured(), $agent, null);

        $this->assertPartRefused($agent, $assignmentId, 'A delivery erasure part must name its account.');
    }

    /** A different channel's execution frame is foreign to the mail assignment. */
    public function testASmsFrameCannotCarryAMailAssignment(): void
    {
        $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);
        $mail = $this->mailAgent('1');
        $sms = new OwnershipSmsAgent('1');
        $this->claimParticipant($sms);
        $assignmentId = $this->deliveryPart($this->openCaptured(), $mail);
        $before = self::deliveryIds();

        try {
            $this->erase($sms, $assignmentId);
            self::fail('The foreign channel carried the assignment');
        } catch (AccountOperationStepRefusedException $refusal) {
            self::assertSame(AccountOperationStepRefusal::ForeignAssignment, $refusal->reason);
        }

        self::assertSame($before, self::deliveryIds());
        self::assertSame([], self::storedResults());
        self::assertSame([], $this->changes->deletions);
    }

    /** A freeze that silences delivery writers refuses before touching a delivery or recording a result. */
    public function testASilencingFreezeDefersThePart(): void
    {
        $this->delivery(self::notification(self::USER_ID), MailDeliveryChannel::NAME, 0);
        $agent = $this->mailAgent('1');
        $assignmentId = $this->deliveryPart($this->openCaptured(), $agent);
        Hilos::$rt = new DeliveryRetryRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
            StateProtectedModeRuntime::circleSessionTokenHashes => [],
            StateProtectedModeRuntime::circleNamedCount => 0,
            StateProtectedModeRuntime::enteredFromBrowser => false,
        ]));

        $this->assertPartRefused($agent, $assignmentId, 'A delivery erasure part waits for maintenance to end.');
    }

    /** A channel with no rows still leaves its immutable zero result. */
    public function testAnEmptyPartRecordsZero(): void
    {
        $agent = $this->mailAgent('1');
        $assignmentId = $this->deliveryPart($this->openCaptured(), $agent);

        self::assertSame('{"deliveries":0}', $this->erase($agent, $assignmentId)->payloadJson);
        self::assertSame([[$assignmentId, $agent->getId(), '{"deliveries":0}']], self::storedResults());
    }

    /** The channel's only result right is Add over the set named by its full shard address. */
    public function testParticipantRightsAndReadDeclarationsAreNarrow(): void
    {
        $agent = $this->mailAgent('2');

        self::assertSame([TruthSourceOperation::Add], $agent::OWNS_DB_SET[HilosDbContext::accountOperationResults]);
        self::assertSame($agent->getId(), $agent->ownedDbSetKey(HilosDbContext::accountOperationResults));
        self::assertSame(MailDeliveryChannelAgent::AGENT_TYPE . ':2', $agent->getId());
        self::assertSame([
            HilosDbContext::notifications,
            HilosDbContext::settings,
            HilosDbContext::accountOperationAssignments,
            HilosDbContext::accountOperations,
        ], $agent::READS_DB);
        self::assertNotContains(HilosDbContext::accountOperationResults, $agent::READS_DB);
    }

    /** @return iterable<string, array{int}> Counts outside the bounded reader's contract */
    public static function invalidLookupSizes(): iterable
    {
        yield 'empty' => [0];
        yield 'too many' => [1001];
    }

    /** @param int $count Number of notification ids passed to the reader */
    #[DataProvider('invalidLookupSizes')]
    public function testIdentityLookupRefusesAnUnboundedOrEmptyList(int $count): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Notification delivery identity lookup takes 1 to 1000 notification ids');

        Hilos::$db->notificationDeliveries->identitiesOfNotifications(MailDeliveryChannel::NAME, array_fill(0, $count, 1));
    }

    /**
     * @param string $index Mail shard index
     * @return OwnershipMailAgent Real registered mail owner with all of its declared rights
     */
    private function mailAgent(string $index): OwnershipMailAgent
    {
        $agent = new OwnershipMailAgent($index);
        $this->claimParticipant($agent);

        return $agent;
    }

    /** @param AbstractDeliveryChannelAgent $agent Real channel instance whose claim must survive until teardown */
    private function claimParticipant(AbstractDeliveryChannelAgent $agent): void
    {
        OwnershipDeclaration::claimAll($agent);
        $this->participantClaims[] = $agent->getId();
    }

    /** @return AccountOperation Open erasure with started request and saved delivery portions */
    private function openCaptured(): AccountOperation
    {
        return $this->asCoordinator(static function (): AccountOperation {
            Database::sql(
                'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at, started_at)'
                    . ' VALUES (?, 1790416800000, 1790416800000, 1790416800000)',
                [self::USER_ID],
            );
            $operation = AccountErasureOpening::open(
                Hilos::$db->accountDeletions[Database::lastInsertId()],
                AccountErasureParticipantCatalog::framework(),
            )->operation;
            self::assertNotNull($operation);
            AccountErasureDeliveryCapture::recordMissing($operation);

            return $operation;
        });
    }

    /**
     * The test key stands in for the coordinator's future working assignment, outside the capture namespace.
     *
     * @param AccountOperation $operation Operation at its current stage
     * @param AbstractDeliveryChannelAgent $agent Addressed channel/shard
     * @param ?int $userId Named account, null for the refusal case
     * @return int Recorded assignment id
     */
    private function deliveryPart(
        AccountOperation $operation,
        AbstractDeliveryChannelAgent $agent,
        ?int $userId = self::USER_ID,
    ): int {
        return $this->asCoordinator(static fn (): int => Hilos::$db->accountOperationAssignments->actions->record(
            $operation->id,
            $operation->stage,
            'test-delivery-part:' . $agent->getId(),
            new AccountOperationOwnerAddress($agent::AGENT_TYPE, $agent->getIndex()),
            $userId,
            AccountOperationAssignmentPayload::fromJson('{}'),
        )->id);
    }

    /**
     * @param AbstractDeliveryChannelAgent $agent Owner receiving its part in its own execution frame
     * @param int $assignmentId Assignment it carries
     * @return AccountOperationResult Recorded or saved outcome
     */
    private function erase(AbstractDeliveryChannelAgent $agent, int $assignmentId): AccountOperationResult
    {
        return ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId()),
            fn (): AccountOperationResult => $agent->runErasurePart($assignmentId),
        );
    }

    /**
     * @param AbstractDeliveryChannelAgent $agent Owner whose part must refuse
     * @param int $assignmentId Assignment it carries
     * @param string $words Exact refusal text
     */
    private function assertPartRefused(AbstractDeliveryChannelAgent $agent, int $assignmentId, string $words): void
    {
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        try {
            $this->erase($agent, $assignmentId);
            self::fail('The erasure part did not refuse');
        } catch (ValidationException $refusal) {
            self::assertNotInstanceOf(AccountOperationStepRefusedException::class, $refusal);
            self::assertSame($words, $refusal->getMessage());
        }
        self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
        self::assertSame([], self::storedResults());
        self::assertSame([], $this->changes->deletions);
    }

    /**
     * @template T
     * @param callable(): T $write Work carried by the real coordinator's frame
     * @return T Result of that work
     */
    private function asCoordinator(callable $write): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY), $write);
    }

    /**
     * @param int $userId Recipient account
     * @return int Saved notification number
     */
    private static function notification(int $userId): int
    {
        Database::sql(
            'INSERT INTO hilos_notification (user_id, type, title, created_at) VALUES (?, ?, ?, 1790416800000)',
            [$userId, 'test.erasure-delivery', 'Erasure delivery notification'],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $notificationId Saved notification number, including a number already captured
     * @param string $channel Channel name
     * @param int $routingKey Saved shard-routing dimension
     * @return int New delivery id created under the library's fixture frame
     */
    private function delivery(int $notificationId, string $channel, int $routingKey): int
    {
        return Hilos::$db->notificationDeliveries->actions->createPending($notificationId, $channel, $routingKey)->id;
    }

    /** @return list<int> Persisted delivery ids, independently of the Object mirror */
    private static function deliveryIds(): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['id'],
            Database::sql('SELECT id FROM hilos_notification_delivery ORDER BY id')->rows(),
        );
    }

    /** @return list<array{int, string, string}> Persisted assignment, owner and payload of each result */
    private static function storedResults(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['assignment_id'], (string)$row['owner_agent_id'], (string)$row['payload_json']],
            Database::sql('SELECT assignment_id, owner_agent_id, payload_json FROM hilos_account_operation_result ORDER BY id')->rows(),
        );
    }
}

/** The real session holder's coordinator rights, without starting its live lifecycle. */
final class ErasureDeliveryCoordinator extends AbstractSessionsLibraryAgent
{
}

/** Captures the immediate mirror facts that prove each removed delivery was announced separately. */
final class ErasureDeliveryChanges implements SourceMirrorSubscriberInterface
{
    /** @var list<SourceChange> Delivery-row delete facts in announcement order */
    public array $deletions = [];

    /**
     * @param SourceChange $change Immediate source fact
     * @param SourceChangeProvenance $provenance Source transport provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB && $change->sourceKey === HilosDbContext::notificationDeliveries
            && $change->mutationType === TableMutationType::Delete) {
            $this->deletions[] = $change;
        }
    }
}

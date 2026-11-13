<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountErasureDeliveryCapture;
use Hilos\Auth\AccountOperation\AccountErasureOpening;
use Hilos\Auth\AccountOperation\AccountErasureParticipantCatalog;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\DTO\NotificationDeliverSignalData;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

/** Adds erasure tables outside the managed rollback scope and releases participant rights with the environment. */
abstract class DeliveryErasingRecipientIntegrationTestCase extends DeliveryRetryOwnerIntegrationTestCase
{
    private const array TABLES = [
        'hilos_identity',
        'hilos_user_merge',
        'hilos_user_verification',
        'hilos_account_operation',
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
    ];

    /** @var list<string> Extra tables raised before the managed rollback scope */
    private array $createdTables = [];
    /** @var list<string> Erasure participant claims and reader interests to release */
    protected array $participantClaims = [];

    /** Mounts the erasure schema before rollback; the ordinary retry harness still owns delivery writes. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        foreach (self::TABLES as $table) {
            Database::sqlRun((string)file_get_contents(
                dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql',
            ));
            $this->createdTables[] = $table;
        }
        Schema::reset();
        Schema::initialize();
    }

    /** Drops extra schema after rollback and releases participant claims even when cleanup fails. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach (array_reverse($this->createdTables) as $table) {
                Database::sqlRun((string)file_get_contents(
                    dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '_down.sql',
                ));
            }
            Schema::reset();
        } finally {
            foreach ($this->participantClaims as $agentId) {
                TruthSourceRegistry::unregisterAgent($agentId);
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            }
            parent::releaseIntegrationEnvironment();
        }
    }
}

/** A begun recipient erasure closes every send entrance and retry before an attempt or reset writes. */
#[IntegrationTest]
final class DeliveryErasingRecipientIntegrationTest extends DeliveryErasingRecipientIntegrationTestCase
{
    /** Adoption skips the closed recipient without consuming an attempt or blocking the next recipient. */
    public function testPendingAdoptionStartsOnlyTheNeighbor(): void
    {
        $closed = Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id;
        self::deletion(true);
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1911, 'Neighbor')");
        Database::sql(
            'INSERT INTO hilos_notification (user_id, type, title, created_at) VALUES (1911, ?, ?, 1)',
            ['test.erasure', 'Neighbor notification'],
        );
        $notificationId = Database::lastInsertId();
        $neighbor = Hilos::$db->notificationDeliveries->actions->createPending($notificationId, MailDeliveryChannel::NAME, 0)->id;
        $agent = new RetryOwnerMailAgent('1');
        $agent->script = [new SettledRetryAttempt(null)];
        $this->changes->list = [];

        $this->tick($agent);

        self::assertSame([[RetryOwnerMailChannel::ADDRESS, $notificationId]], $agent->started);
        self::assertSame([DeliveryStatus::PENDING, '0'], self::savedAttempt($closed));
        self::assertSame([DeliveryStatus::PENDING, '1'], self::savedAttempt($neighbor));
        self::assertSame([$neighbor], self::queued($agent));
        self::assertSame([(string)$neighbor], array_column($this->changes->list, 'sourceId'));
        self::assertSame([], $agent->errors);
    }

    /** An explicit deliver frame reaches the same start guard after the initial adoption is over. */
    public function testDeliverFrameDoesNotStartAnErasingRecipientsAttempt(): void
    {
        $agent = new RetryOwnerMailAgent('1');
        $this->tick($agent);
        $id = Hilos::$db->notificationDeliveries->actions->createPending($this->notificationId, MailDeliveryChannel::NAME, 0)->id;
        self::deletion(true);
        $this->changes->list = [];
        $this->asClaimant($agent->getId(), fn () => $agent->onSignalAgent(
            new AgentSignalData(new NotificationDeliverSignalData($id, $this->notificationId, MailDeliveryChannel::NAME, 1)),
            '',
            HilosSignalConstants::HILOS_MAIL_DELIVER,
        ));
        self::assertSame([$id], self::queued($agent));

        $this->tick($agent);

        self::assertSame([DeliveryStatus::PENDING, '0'], self::savedAttempt($id));
        self::assertSame([], $agent->started);
        self::assertSame([], self::queued($agent));
        self::assertSame([], $this->changes->list);
        self::assertSame([], $agent->errors);
    }

    /** @return iterable<string, array{bool}> A begun erasure and a deletion still in its grace period */
    public static function deletionStates(): iterable
    {
        yield 'erasure started' => [true];
        yield 'deletion scheduled' => [false];
    }

    /**
     * The owner answers once; only a scheduled deletion permits the reset and its next attempt.
     *
     * @param bool $started Whether the recipient's erasure has actually begun
     */
    #[DataProvider('deletionStates')]
    public function testRetryIsRefusedOnlyAfterErasureStarts(bool $started): void
    {
        $id = $this->failedDelivery();
        self::deletion($started);
        $agent = new RetryOwnerMailAgent('1');
        $ask = $this->retryAsk($id);
        $before = Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows();
        $this->changes->list = [];

        $this->deliverRetry($agent, $ask);

        $answer = $this->assertAnsweredOnce(
            $ask,
            $started ? 'Delivery retry is refused: the recipient\'s account is being deleted' : null,
        );
        self::assertSame([null, null], [$answer->errorType, $answer->errorDetail]);
        self::assertSame([], $agent->started);
        self::assertSame([], $agent->errors);
        if ($started) {
            self::assertSame($before, Database::sql('SELECT * FROM hilos_notification_delivery ORDER BY id')->rows());
            self::assertSame([], self::queued($agent));
            self::assertSame([], $this->changes->list);
        } else {
            self::assertSame([DeliveryStatus::PENDING, '0'], self::savedAttempt($id));
            self::assertSame([$id], self::queued($agent));
            self::assertCount(1, $this->changes->list);
            $agent->script = [new SettledRetryAttempt(null)];
            $this->tick($agent);
            self::assertSame([[RetryOwnerMailChannel::ADDRESS, $this->notificationId]], $agent->started);
            self::assertSame([DeliveryStatus::PENDING, '1'], self::savedAttempt($id));
        }
    }

    /** A retry already addressed before the channel's erasure part still gets the ordinary missing-row answer. */
    public function testRetryAfterTheChannelsErasurePartSaysUnknownDelivery(): void
    {
        $id = $this->failedDelivery();
        $ask = $this->retryAsk($id);
        $deletionId = self::deletion(true);
        $agent = new RetryOwnerMailAgent('1');
        OwnershipDeclaration::claimDb(ErasureDeliveryCoordinator::class, HilosAgentType::HILOS_SESSIONS_LIBRARY);
        $this->participantClaims[] = HilosAgentType::HILOS_SESSIONS_LIBRARY;
        OwnershipDeclaration::claimAll($agent);
        $this->participantClaims[] = $agent->getId();
        $assignmentId = ExecutionContext::run(
            new ExecutionFrame(agentId: HilosAgentType::HILOS_SESSIONS_LIBRARY),
            static function () use ($deletionId, $agent): int {
                $operation = AccountErasureOpening::open(
                    Hilos::$db->accountDeletions[$deletionId],
                    AccountErasureParticipantCatalog::framework(),
                )->operation;
                self::assertNotNull($operation);
                AccountErasureDeliveryCapture::recordMissing($operation);

                return Hilos::$db->accountOperationAssignments->actions->record(
                    $operation->id,
                    $operation->stage,
                    'test-delivery-part:' . $agent->getId(),
                    new AccountOperationOwnerAddress($agent::AGENT_TYPE, $agent->getIndex()),
                    1910,
                    AccountOperationAssignmentPayload::fromJson('{}'),
                )->id;
            },
        );

        $result = ExecutionContext::run(
            new ExecutionFrame(agentId: $agent->getId()),
            static fn () => $agent->runErasurePart($assignmentId),
        );
        self::assertSame('{"deliveries":1}', $result->payloadJson);
        self::assertSame([], Database::sql('SELECT * FROM hilos_notification_delivery')->rows());
        $this->changes->list = [];
        $this->deliverRetry($agent, $ask);

        $this->assertAnsweredOnce($ask, "Unknown delivery: {$id}");
        self::assertSame([], Database::sql('SELECT * FROM hilos_notification_delivery')->rows());
        self::assertSame([], self::queued($agent));
        self::assertSame([], $agent->started);
        self::assertSame([], $agent->errors);
        self::assertSame([], $this->changes->list);
    }

    /**
     * @param bool $started Whether to mark the standing deletion as begun
     * @return int Saved deletion request id
     */
    private static function deletion(bool $started): int
    {
        Database::sql(
            'INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at, started_at)'
                . ' VALUES (1910, 1790416800000, 1790416800000, ?)',
            [$started ? 1790416800000 : null],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $id Saved delivery id
     * @return array{string, string} Status and attempt count read past the ORM
     */
    private static function savedAttempt(int $id): array
    {
        return array_values(Database::sql('SELECT status, attempts FROM hilos_notification_delivery WHERE id = ?', [$id])->firstRow());
    }
}

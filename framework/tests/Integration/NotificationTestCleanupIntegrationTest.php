<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\NotificationDispatcher;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserNotificationTestCleanupSignalData;

require_once __DIR__ . '/SettingsTestWriteIntegrationTest.php';

/** Schema and ownership lifetime stays outside the managed rollback case. */
abstract class NotificationTestCleanupEnvironment extends SettingsTestWriteEnvironment
{
    private const array TABLES = ['hilos_notification', 'hilos_notification_delivery'];

    protected NotificationCleanupTestLibrary $library;
    /** @var array<int, AbstractUserAgent> Personal owners started by the case */
    protected array $people = [];

    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Schema::reset();
        Schema::initialize();
    }

    protected function releaseIntegrationEnvironment(): void
    {
        try {
            foreach ($this->people as $person) {
                TruthSourceRegistry::unregisterAgent($person->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($person->getId()));
            }
            $this->people = [];
            if (isset($this->library)) {
                TruthSourceRegistry::unregisterAgent($this->library->getId());
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));
            }
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
            Schema::reset();
            Schema::initialize();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /** @param string $table Existing stub @param bool $down Whether to drop it */
    private function stub(string $table, bool $down): void
    {
        $name = 'create_' . $table;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }
}

/** Exact test cleanup uses the real library and personal owner under their unchanged claims. */
#[IntegrationTest]
final class NotificationTestCleanupIntegrationTest extends NotificationTestCleanupEnvironment
{
    private const string TITLE = 'Owned test notification';
    protected function setUpInTransaction(): void
    {
        $this->library = new NotificationCleanupTestLibrary();
        OwnershipDeclaration::claimAll($this->library);
    }

    public function testSentDeliverySurvivesExactCleanupAndRepeatedCleanupIsAbsent(): void
    {
        $userId = $this->user();
        $id = $this->emit($userId);
        $deliveryId = $this->delivery($id, DeliveryStatus::SENT);
        self::assertSame([
            'notificationId' => $id,
            'queuedChannels' => ['email'],
            'deliveries' => [['deliveryId' => $deliveryId, 'channel' => 'email', 'status' => DeliveryStatus::SENT]],
        ], $this->command(CliCommands::NOTIFICATION_TEST_INSPECT, $userId)->payload);
        self::assertSame(['deleted' => true], $this->command(CliCommands::NOTIFICATION_TEST_CLEANUP, $userId)->payload);
        self::assertSame([], Database::sql('SELECT `id` FROM hilos_notification WHERE id = ?', [$id])->rows());
        self::assertCount(1, Database::sql('SELECT `id` FROM hilos_notification_delivery WHERE id = ?', [$deliveryId])->rows());
        self::assertSame(['deleted' => false], $this->command(CliCommands::NOTIFICATION_TEST_CLEANUP, $userId)->payload);
        self::assertSame(['notificationId' => null, 'queuedChannels' => [], 'deliveries' => []],
            $this->command(CliCommands::NOTIFICATION_TEST_INSPECT, $userId)->payload);
    }

    public function testFailedDeliveryAndNoDeliveryAreBothTerminalForCleanup(): void
    {
        foreach ([DeliveryStatus::FAILED, null] as $status) {
            $userId = $this->user();
            $id = $this->emit($userId);
            if ($status !== null) {
                $this->delivery($id, $status);
            }
            self::assertSame(['deleted' => true], $this->command(CliCommands::NOTIFICATION_TEST_CLEANUP, $userId)->payload);
            self::assertSame([], Database::sql('SELECT `id` FROM hilos_notification WHERE id = ?', [$id])->rows());
        }
    }

    public function testSameTitleOnAnotherRecipientAndForgedOwnerAsksCannotDeleteForeignRows(): void
    {
        $userId = $this->user();
        $otherUserId = $this->user();
        $id = $this->emit($userId);
        $otherId = $this->emit($otherUserId);
        foreach ([
            new UserNotificationTestCleanupSignalData($userId, $otherId, self::TITLE, 'foreign-row'),
            new UserNotificationTestCleanupSignalData($userId, $id, 'Another title', 'wrong-title'),
            new UserNotificationTestCleanupSignalData($otherUserId, $otherId, self::TITLE, 'wrong-address'),
        ] as $ask) {
            self::assertFalse($this->personalCommand($userId, $ask)->isOk());
        }
        self::assertSame(['deleted' => true], $this->command(CliCommands::NOTIFICATION_TEST_CLEANUP, $userId)->payload);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_notification WHERE id = ?', [$otherId])->rows());
        self::assertSame($otherId, $this->command(CliCommands::NOTIFICATION_TEST_INSPECT, $otherUserId)->payload['notificationId']);
    }

    public function testPendingDeliveryRefusesCleanupWithoutAWrite(): void
    {
        $userId = $this->user();
        $id = $this->emit($userId);
        $this->delivery($id, DeliveryStatus::PENDING);
        $reply = $this->command(CliCommands::NOTIFICATION_TEST_CLEANUP, $userId);
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('nonterminal', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_notification WHERE id = ?', [$id])->rows());
        self::assertSame(DeliveryStatus::PENDING,
            $this->command(CliCommands::NOTIFICATION_TEST_INSPECT, $userId)->payload['deliveries'][0]['status']);
    }

    public function testDuplicateSubjectsAreRefusedAndLibraryCannotRemoveRowsItCreated(): void
    {
        $userId = $this->user();
        $id = $this->emit($userId);
        $this->emit($userId);
        foreach ([CliCommands::NOTIFICATION_TEST_INSPECT, CliCommands::NOTIFICATION_TEST_CLEANUP] as $command) {
            self::assertFalse($this->command($command, $userId)->isOk());
        }
        self::assertCount(2, Database::sql('SELECT id FROM hilos_notification WHERE user_id = ?', [$userId])->rows());
        try {
            $this->asLibrary(static fn () => Hilos::$db->notifications[$id]->actions->deleteForTest());
            self::fail('Add alone must not grant the library Remove');
        } catch (WriteNotAllowedException) {
            self::assertCount(2, Database::sql('SELECT id FROM hilos_notification WHERE user_id = ?', [$userId])->rows());
        }
        $environment = Hilos::$env;
        Hilos::$env = null;
        try {
            self::assertFalse($this->personalCommand($userId,
                new UserNotificationTestCleanupSignalData($userId, $id, self::TITLE, 'closed-gate'))->isOk());
            try {
                $this->asLibrary(static fn () => Hilos::$db->notifications[$id]->actions->deleteForTest());
                self::fail('The item action must repeat the non-production gate');
            } catch (ValidationException) {
                self::assertCount(2, Database::sql('SELECT id FROM hilos_notification WHERE user_id = ?', [$userId])->rows());
            }
        } finally {
            Hilos::$env = $environment;
        }
    }

    public function testPartialEmitIsInspectedAndCleanedWithoutEmittingAgain(): void
    {
        $userId = $this->user();
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->willThrowException(new ValidationException('Dispatch failed'));
        $this->library = new NotificationCleanupTestLibrary($dispatcher);
        $reply = $this->command(CliCommands::NOTIFICATION_TEST_EMIT, $userId, ['type' => 'test.owned']);
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('may already exist', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        $observed = $this->command(CliCommands::NOTIFICATION_TEST_INSPECT, $userId);
        self::assertTrue($observed->isOk());
        self::assertIsInt($observed->payload['notificationId']);
        self::assertSame([], $observed->payload['deliveries']);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_notification WHERE user_id = ?', [$userId])->rows());
        self::assertSame(['deleted' => true], $this->command(CliCommands::NOTIFICATION_TEST_CLEANUP, $userId)->payload);
        $gone = new UserNotificationTestCleanupSignalData($userId, $observed->payload['notificationId'], self::TITLE, 'already-gone');
        self::assertSame(['deleted' => false], $this->personalCommand($userId, $gone)->payload);
    }

    /** @return int New recipient */
    private function user(): int
    {
        Database::sql("INSERT INTO hilos_user (`name`, `admin`) VALUES ('Notification test', 0)");

        return Database::lastInsertId();
    }

    /** @param int $userId Recipient @return int Notification id */
    private function emit(int $userId): int
    {
        $reply = $this->command(CliCommands::NOTIFICATION_TEST_EMIT, $userId, ['type' => 'test.owned']);
        self::assertTrue($reply->isOk(), json_encode($reply->payload));

        return $reply->payload['notificationId'];
    }

    /**
     * @param int $id Notification id
     * @param string $status Initial fixture state, before the journal has been loaded
     * @return int Delivery id
     */
    private function delivery(int $id, string $status): int
    {
        Database::sql('INSERT INTO hilos_notification_delivery (notification_id, channel, status, created_at, updated_at)'
            . " VALUES (?, 'email', ?, 1, 1)", [$id, $status]);

        return Database::lastInsertId();
    }

    /**
     * @param string $command Generic command name
     * @param int $userId Exact recipient
     * @param array<string, mixed> $extra Additional emit fields
     * @return CommandReplyDTO Single original command reply
     */
    private function command(string $command, int $userId, array $extra = []): CommandReplyDTO
    {
        $this->asLibrary(fn () => $this->library->onSignalCommand(
            new CommandRequestDTO('notification-cleanup-test', $command, ['userId' => $userId, 'title' => self::TITLE] + $extra),
            'cli', $command,
        ));
        $reply = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData) {
                $ask = $signal->data->data;
                self::assertInstanceOf(UserNotificationTestCleanupSignalData::class, $ask);
                $this->deliver($ask->userId, UserNotificationTestCleanupSignalData::fromArray($ask->toArray()));
                continue;
            }
            if ($signal->signalType->getType() !== SignalTypeConstants::COMMAND_REPLY) {
                continue;
            }
            self::assertNull($reply, 'Exactly one reply is owed');
            self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
            self::assertSame('notification-cleanup-test', $signal->data->correlationId);
            $reply = $signal->data;
        }
        self::assertNotNull($reply);

        return $reply;
    }

    /**
     * @param int $userId Addressed personal agent
     * @param UserNotificationTestCleanupSignalData $ask Command forwarded by the library
     * @return CommandReplyDTO Direct command reply
     */
    private function personalCommand(int $userId, UserNotificationTestCleanupSignalData $ask): CommandReplyDTO
    {
        $this->deliver($userId, $ask);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(CommandReplyDTO::class, $signal?->data);
        self::assertSame($ask->correlationId, $signal->data->correlationId);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        return $signal->data;
    }

    /**
     * @param int $userId Agent index, independently checked against the payload
     * @param UserNotificationTestCleanupSignalData $ask Exact cleanup request
     */
    private function deliver(int $userId, UserNotificationTestCleanupSignalData $ask): void
    {
        if (!isset($this->people[$userId])) {
            $person = new IntegrationUserAgent((string)$userId);
            OwnershipDeclaration::claimAll($person);
            $this->people[$userId] = $person;
        }
        $person = $this->people[$userId];
        ExecutionContext::run(new ExecutionFrame(agentId: $person->getId()), static fn () => $person->onSignalAgent(
            new AgentSignalData($ask), 'agent', HilosSignalConstants::HILOS_USER_NOTIFICATION_TEST_CLEANUP,
        ));
    }

    /**
     * @template T
     * @param callable(): T $step Operation run in the library's frame
     * @return T Operation result
     */
    private function asLibrary(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), $step);
    }


}

/** The real library's claims, command routes and handlers, with an injectable dispatcher. */
final class NotificationCleanupTestLibrary extends AbstractNotificationsLibraryAgent
{
}


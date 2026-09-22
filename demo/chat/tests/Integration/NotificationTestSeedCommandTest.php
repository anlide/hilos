<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\NotificationTestSeedCommand;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Utils\Helpers\RandomHelper;
use Random\RandomException;

/**
 * Integration tests for test:notification:seed against the chat database.
 *
 * The command resolves its recipient by email, writes deterministic rows, stamps
 * only the oldest requested rows read, preserves the unread count, and refuses a
 * stale recipient or an address with no account.
 */
final class NotificationTestSeedCommandTest extends IntegrationTestCase
{
    private const int SEED_COUNT = 5;
    private const int READ_COUNT = 2;

    /**
     * @throws HilosException When the fixture user or notifications cannot be written or read
     * @throws RandomException When the fixture identifier cannot be generated
     */
    public function testSeedsRowsAndMarksOnlyTheOldestRequestedRowsRead(): void
    {
        [$userId, $email] = $this->createRecipient();

        self::assertSame(ExitCode::SUCCESS, $this->runSeed($email, self::SEED_COUNT, self::READ_COUNT));

        $notifications = Hilos::$db->notifications->objectCollection->listForUser($userId, self::SEED_COUNT + 1);
        self::assertCount(self::SEED_COUNT, $notifications);
        self::assertSame(
            [
                'Integration notification 005',
                'Integration notification 004',
                'Integration notification 003',
                'Integration notification 002',
                'Integration notification 001',
            ],
            array_map(static fn ($notification): string => $notification->title, $notifications),
        );
        self::assertSame(
            [false, false, false, true, true],
            array_map(static fn ($notification): bool => $notification->readAt !== null, $notifications),
        );
        self::assertSame(
            self::SEED_COUNT - self::READ_COUNT,
            Hilos::$db->notifications->objectCollection->countUnreadForUser($userId),
        );
    }

    /**
     * @throws HilosException When the fixture user or notification write fails
     * @throws RandomException When the fixture identifier cannot be generated
     */
    public function testRefusesASecondRunForTheSameRecipient(): void
    {
        [, $email] = $this->createRecipient();
        self::assertSame(ExitCode::SUCCESS, $this->runSeed($email, self::SEED_COUNT, self::READ_COUNT));

        $this->expectOutputRegex('/already has notifications/');
        self::assertSame(
            ExitCode::ERROR,
            new NotificationTestSeedCommand()->execute(
                ['user' => $email, 'read' => (string)self::READ_COUNT, 'prefix' => 'Integration notification'],
                [(string)self::SEED_COUNT],
            ),
        );
    }

    /**
     * @throws HilosException When resolving the unknown address fails
     */
    public function testUnknownAddressIsAConfigurationError(): void
    {
        $email = 'missing-notification-recipient@example.test';

        $this->expectOutputRegex('/run test:user:seed first/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            new NotificationTestSeedCommand()->execute(['user' => $email], ['5']),
        );
    }

    /**
     * @throws HilosException When the fixture user, notifications, or deliveries cannot be written or read
     * @throws RandomException When the fixture identifier cannot be generated
     */
    public function testSeedsDeliveriesWhenDeliveredChannelIsGiven(): void
    {
        [$userId, $email] = $this->createRecipient();

        self::assertSame(
            ExitCode::SUCCESS,
            $this->runSeed($email, self::SEED_COUNT, 0, 'email'),
        );

        $notifications = Hilos::$db->notifications->objectCollection->listForUser($userId, self::SEED_COUNT + 1);
        self::assertCount(self::SEED_COUNT, $notifications);

        $deliveries = Hilos::$db->notificationDeliveries->objectCollection;
        foreach ($notifications as $notification) {
            self::assertNotNull($notification->id);
            $delivery = $deliveries->findFor($notification->id, 'email');
            self::assertNotNull($delivery);
            self::assertSame(DeliveryStatus::SENT, $delivery->status);
            self::assertSame(1, $delivery->attempts);
            self::assertNotNull($delivery->deliveredAt);
        }
    }

    /**
     * @throws HilosException When resolving the recipient fails
     * @throws RandomException When the fixture identifier cannot be generated
     */
    public function testRejectsUnknownDeliveryChannelBeforeWritingAnyNotification(): void
    {
        [$userId, $email] = $this->createRecipient();

        $this->expectOutputRegex('/Unknown delivery channel/');
        $exitCode = new NotificationTestSeedCommand()->execute(
            [
                'user' => $email,
                'delivered' => 'unregistered-channel-name',
                'prefix' => 'Integration notification',
            ],
            [(string)self::SEED_COUNT],
        );

        self::assertSame(ExitCode::INVALID_ARGUMENT, $exitCode);
        self::assertSame([], Hilos::$db->notifications->objectCollection->listForUser($userId, 1));
    }

    /**
     * Creates a fixture account with a password identity the command can resolve.
     *
     * @return array{int, string} User id and email
     * @throws HilosException When the user or identity cannot be written
     * @throws RandomException When the fixture identifier cannot be generated
     */
    private function createRecipient(): array
    {
        $identifier = 'notification-seed-' . RandomHelper::hex(4);
        $userId = Hilos::$db->users->actions->createWithName($identifier)->id;
        self::assertNotNull($userId);

        $email = $identifier . '@example.test';
        Hilos::$db->identities->createPasswordIdentity($userId, $email, 'correct horse battery');

        return [$userId, $email];
    }

    /**
     * Runs the seed command while swallowing its human-facing summary.
     *
     * @param string $email Recipient email
     * @param int $count Number of notifications to seed
     * @param int $read Number of oldest notifications to mark read
     * @param ?string $delivered Optional delivery channel name
     * @return int Command exit code
     * @throws HilosException When the command's lookup or write fails
     */
    private function runSeed(string $email, int $count, int $read, ?string $delivered = null): int
    {
        $options = [
            'user' => $email,
            'read' => (string)$read,
            'prefix' => 'Integration notification',
        ];
        if ($delivered !== null) {
            $options['delivered'] = $delivered;
        }

        ob_start();
        try {
            return new NotificationTestSeedCommand()->execute(
                $options,
                [(string)$count],
            );
        } finally {
            ob_end_clean();
        }
    }
}

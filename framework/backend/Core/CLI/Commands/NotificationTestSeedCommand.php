<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\NotificationSeverity;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Test-only: bulk-seed notifications for one fixture user before the daemon starts.
 *
 * The command writes deterministic volume fixtures directly through the notifications
 * object collection. The live emit seam is intentionally bypassed: it needs a daemon
 * router and dispatches delivery channels, while this fixture prepares durable rows for
 * the daemon to read when the stand comes up. A recipient with any existing notification
 * is refused so a stale database cannot silently double the fixture.
 *
 * With --delivered=<channel>, each notification also receives one delivery journal row on
 * that channel, written directly in terminal sent state without dispatching, to prepare a
 * delivery journal exceeding the table count ceiling on the test stand (HIL-1077).
 */
final class NotificationTestSeedCommand extends TestOnlyCommand
{
    /** @var array<string, list<TruthSourceOperation>> Notification rows this command seeds, claimed by its runner */
    public const array OWNS_DB = [
        HilosDbContext::notifications => TruthSourceOperation::BY_KIND,
        HilosDbContext::notificationDeliveries => TruthSourceOperation::BY_KIND,
    ];

    /** @var string Default fixture recipient when --user is not given */
    private const string DEFAULT_USER = 'seed-001@example.test';

    /** @var string Default title prefix when --prefix is not given */
    private const string DEFAULT_PREFIX = 'Seed notification';

    /** @var string Machine type assigned to every seeded notification */
    private const string TYPE = 'test.seed';

    /** @var int Minimum zero-padding width for title suffixes */
    private const int MIN_SUFFIX_WIDTH = 3;

    /**
     * @return string Command name
     */
    public function getName(): string
    {
        return CliCommands::NOTIFICATION_TEST_SEED;
    }

    /**
     * Declares the offline fixture-write departure.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'seeds stand notifications and their delivery journal rows from composer test:db-prepare, which runs before the stand daemon comes up',
        );
    }

    /**
     * @return string One-line command description
     */
    public function getDescription(): string
    {
        return 'Bulk-seed N notification fixtures for one user (test-only)';
    }

    /**
     * @return string Command help text
     */
    public function getHelp(): string
    {
        $defaultUser = self::DEFAULT_USER;
        $defaultPrefix = self::DEFAULT_PREFIX;

        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Seeds <count> deterministic notification rows for one fixture user. The oldest
<read> rows are stamped read. Strictly not idempotent: the recipient must have no
notifications before this command runs.

Usage:
  php cli.php test:notification:seed <count> [--user=<email>] [--read=<k>] [--prefix=<p>] [--delivered=<channel>]

Arguments:
  <count>       Positive integer: how many notifications to seed

Options:
  --user=<email>        Recipient address (default: {$defaultUser})
  --read=<k>            Number of oldest rows to stamp read (default: 0)
  --prefix=<p>          Notification title prefix (default: {$defaultPrefix})
  --delivered=<channel> Journal one sent delivery per notification on this registered channel
HELP;
    }

    /**
     * Builds a deterministic title with a 1-based, zero-padded suffix.
     *
     * @param int $index 1-based notification index
     * @param int $count Total notifications being seeded
     * @param string $prefix Title prefix
     * @return string Fixture title
     */
    public static function fixtureTitle(int $index, int $count, string $prefix): string
    {
        return sprintf(
            '%s %s',
            $prefix,
            str_pad((string)$index, max(self::MIN_SUFFIX_WIDTH, strlen((string)$count)), '0', STR_PAD_LEFT),
        );
    }

    /**
     * Seeds deterministic notifications for one existing fixture user.
     *
     * @param array<string, mixed> $options Parsed options: --user, --read, --prefix, --delivered
     * @param list<string> $args Positional args: [0] count
     * @return int Exit code
     * @throws HilosException When a database lookup or notification write fails
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the next line
        $countArg = $args[0] ?? '';
        if (preg_match('/^\d+$/', $countArg) !== 1 || (int)$countArg <= 0) {
            $this->printUsage();

            return ExitCode::INVALID_ARGUMENT;
        }
        $count = (int)$countArg;

        $user = $options['user'] ?? self::DEFAULT_USER;
        if (!is_string($user) || $user === '') {
            echo "Option --user must be a non-empty string.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $readArg = $options['read'] ?? '0';
        if (!is_string($readArg) || preg_match('/^\d+$/', $readArg) !== 1 || (int)$readArg > $count) {
            $this->printUsage();

            return ExitCode::INVALID_ARGUMENT;
        }
        $read = (int)$readArg;

        $prefix = $options['prefix'] ?? self::DEFAULT_PREFIX;
        if (!is_string($prefix) || $prefix === '') {
            echo "Option --prefix must be a non-empty string.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $channel = null;
        if (array_key_exists('delivered', $options)) {
            $deliveredOption = $options['delivered'];
            if (!is_string($deliveredOption) || $deliveredOption === '') {
                $this->printUsage();

                return ExitCode::INVALID_ARGUMENT;
            }
            $channel = $deliveredOption;

            $registeredChannels = array_keys(Hilos::notificationChannelRegistryClass()::all());
            if (!in_array($channel, $registeredChannels, true)) {
                echo "Unknown delivery channel {$channel}; registered: " . implode(', ', $registeredChannels) . "\n";

                return ExitCode::INVALID_ARGUMENT;
            }
        }

        try {
            if ($channel !== null) {
                Schema::requireTable(EntityNotificationDelivery::_table);
            }

            $userId = Hilos::$db->identities->findUserIdByEmail($user);
            if ($userId === null) {
                echo "No user found for {$user}; run test:user:seed first.\n";

                return ExitCode::CONFIG_ERROR;
            }

            $notifications = Hilos::$db->notifications->objectCollection;

            if ($notifications->listForUser($userId, 1) !== []) {
                echo "Recipient {$user} already has notifications; run test:db:reset first.\n";

                return ExitCode::ERROR;
            }

            $deliveries = $channel !== null ? Hilos::$db->notificationDeliveries->objectCollection : null;

            $firstId = 0;
            $lastId = 0;
            for ($index = 1; $index <= $count; $index++) {
                $notification = $notifications->createFor(
                    $userId,
                    self::TYPE,
                    NotificationSeverity::INFO,
                    self::fixtureTitle($index, $count, $prefix),
                    null,
                    null,
                );
                if ($index <= $read) {
                    $notification->readAt = TimeHelper::getSqlDateTime();
                    $notification->sync();
                }

                $lastId = $notification->id
                    ?? throw new DatabaseException('Notification insert did not assign an id');
                if ($index === 1) {
                    $firstId = $lastId;
                }

                if ($deliveries !== null) {
                    $delivery = $deliveries->createPending($lastId, $channel);
                    $deliveries->beginAttempt($delivery);
                    $deliveries->markSent($delivery);
                }
            }
        } catch (TableNotActivatedException $exception) {
            echo "Cannot seed notifications: {$exception->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        $summary = "Seeded {$count} notifications for {$user} (ids {$firstId}..{$lastId}, {$read} read)";
        if ($channel !== null) {
            $summary .= ", each delivered via {$channel}";
        }
        echo "{$summary}\n";

        return ExitCode::SUCCESS;
    }

    /** Prints the command usage after an argument refusal. */
    private function printUsage(): void
    {
        echo "Usage: {$this->getName()} <count> [--user=<email>] [--read=<k>] [--prefix=<p>] [--delivered=<channel>]"
            . "  (count: positive integer; read: 0..count)\n";
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use DateTimeZone;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Test-only: bulk-seed N fixture users, each with an unverified password identity and optional IANA time zone.
 *
 * An e2e author needs the /hilos/users viewport table populated on volume (virtual
 * scroll, window paging, server search) without registering N users through the form.
 * The command names users deterministically by prefix and 1-based index, so the spec
 * knows the emails up front and never parses stdout. It hashes the shared password once
 * — password_hash(PASSWORD_DEFAULT) measured at ~340ms, and N calls would dominate
 * stand bring-up — and reuses the hash for every seeded user (fixtures need no per-user
 * salt). Seeding a user row goes through the {@see Hilos::createFixtureUser()} seam,
 * which only a project that owns a users table implements; a project without it gets a
 * clear message and {@see ExitCode::CONFIG_ERROR}, not a fatal. Runs only on the stand
 * bring-up (no daemon), so it registers itself as the identities truth source (the CLI
 * has no agent), mirroring {@see OrphanTestCreateCommand}. Strictly not idempotent: a
 * pre-existing identity for a generated email surfaces as a non-zero exit rather than a
 * silent skip, so a stale database (test:db:reset did not run) is caught, not masked.
 */
final class UserTestSeedCommand extends TestOnlyCommand
{
    /** @var array<string, list<TruthSourceOperation>> Identity rows this command seeds, claimed for it by its runner */
    public const array OWNS_DB = [HilosDbContext::identities => TruthSourceOperation::BY_KIND];

    /** @var list<string> Option names this command reads, without the leading dashes */
    public const array OPTIONS = [
        self::OPTION_PREFIX,
        self::OPTION_PASSWORD,
        self::OPTION_TIME_ZONE,
    ];

    private const string OPTION_PREFIX = 'prefix';

    private const string OPTION_PASSWORD = 'password';

    private const string OPTION_TIME_ZONE = 'time-zone';

    /** @var string Default identifier prefix when --prefix is not given */
    private const string DEFAULT_PREFIX = 'seed';

    /** @var string Default password; kept in sync with PASSWORD in tests/e2e/helpers/session.ts */
    private const string DEFAULT_PASSWORD = 'correct horse battery';

    /** @var string Email domain for generated identifiers */
    private const string EMAIL_DOMAIN = '@example.test';

    /** @var int Minimum zero-padding width for the numeric suffix */
    private const int MIN_SUFFIX_WIDTH = 3;

    public function getName(): string
    {
        return CliCommands::USER_TEST_SEED;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'seeds stand accounts from composer test:db-prepare, which runs before the stand\'s daemon comes up',
        );
    }

    public function getDescription(): string
    {
        return 'Bulk-seed N fixture users with password identities and an optional IANA time zone (test-only)';
    }

    public function getHelp(): string
    {
        $defaultPrefix = self::DEFAULT_PREFIX;

        return <<<HELP
Test-only command (refused unless APP_ENV is non-production).

Seeds <count> fixture users, each with an unverified password identity, for e2e
fixtures on volume. Users are named <prefix>-<index><domain> with a 1-based,
zero-padded index, so a test knows the emails without reading stdout. The password is
hashed once and shared by all seeded users. Strictly not idempotent: a collision on a
generated identifier fails with a non-zero exit (a signal that test:db:reset did not
run). A project that does not implement the fixture-user seam is reported with a config
error, not a fatal.

Usage:
  php cli.php test:user:seed <count> [--prefix=<p>] [--password=<p>] [--time-zone=<IANA>]

Arguments:
  <count>       Positive integer: how many users to seed

Options:
  --prefix=<p>   Identifier/name prefix (default: {$defaultPrefix})
  --password=<p> Shared plaintext password (default: matches the e2e helper's PASSWORD)
  --time-zone=<IANA> Exact IANA time zone to store on every fixture user

Examples:
  php cli.php test:user:seed 25
  php cli.php test:user:seed 200 --prefix=load --password=hunter2
  php cli.php test:user:seed 1 --prefix=timezone-chosen --time-zone=Pacific/Honolulu
HELP;
    }

    /**
     * Seeds `count` users, hashing the shared password once and reusing it for all.
     *
     * @param array<string, mixed> $options Parsed options: --prefix, --password, --time-zone
     * @param list<string> $args Positional args: [0] count
     * @return int Exit code (0 on success)
     * @throws DatabaseException When the user or identity write fails
     * @throws DuplicateValueException When an identity for a generated email already exists
     * @throws EmptyValueException When a generated identifier is empty (unreachable: built here)
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws ItemNotFoundException When a created user is missing from the user collection
     * @throws ItemNotFoundForUpdateException When a created user has no persisted id
     * @throws LogicException When collection class constants are not configured
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ValidationException When the user time zone is not accepted by the user action
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws HilosException When persisting a user time zone fails
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $countArg = $args[0] ?? '';
        if (preg_match('/^\d+$/', $countArg) !== 1 || (int)$countArg <= 0) {
            echo "Usage: {$this->getName()} <count> [--prefix=<p>] [--password=<p>] [--time-zone=<IANA>]"
                . "  (count: positive integer)\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        $count = (int)$countArg;

        $prefix = $options[self::OPTION_PREFIX] ?? self::DEFAULT_PREFIX;
        if (!is_string($prefix) || $prefix === '') {
            echo "Option --prefix must be a non-empty string.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $password = $options[self::OPTION_PASSWORD] ?? self::DEFAULT_PASSWORD;
        if (!is_string($password) || $password === '') {
            echo "Option --password must be a non-empty string.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $timeZone = null;
        if (array_key_exists(self::OPTION_TIME_ZONE, $options)) {
            $timeZoneOption = $options[self::OPTION_TIME_ZONE];
            if (
                !is_string($timeZoneOption)
                || !in_array($timeZoneOption, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            ) {
                echo "Option --time-zone must be an exact IANA time zone identifier.\n";

                return ExitCode::INVALID_ARGUMENT;
            }
            $timeZone = $timeZoneOption;
        }

        // One bcrypt for the whole run: password_hash(PASSWORD_DEFAULT) is ~340ms and all
        // seeded users share the same password, so per-user hashing would dominate bring-up.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $firstEmail = '';
        $lastEmail = '';
        $minId = 0;
        $maxId = 0;
        for ($i = 1; $i <= $count; $i++) {
            $local = self::fixtureIdentifier($i, $count, $prefix);
            $email = $local . self::EMAIL_DOMAIN;

            $userId = Hilos::appClass()::createFixtureUser($local);
            if ($userId === null) {
                // Checked on the first user, before any identity is written, so an unsupported
                // project leaves no partial seed behind.
                echo "This project does not support fixture users (no createFixtureUser seam).\n";

                return ExitCode::CONFIG_ERROR;
            }

            if ($timeZone !== null) {
                Hilos::$db->users[$userId]->actions->setTimeZone($timeZone);
            }

            Hilos::$db->identities->createPasswordIdentityWithHash($userId, $email, $hash);

            if ($i === 1) {
                $firstEmail = $email;
                $minId = $userId;
            }
            $lastEmail = $email;
            $maxId = $userId;
        }

        echo "Seeded {$count} users: {$firstEmail} .. {$lastEmail} (ids {$minId}..{$maxId})\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Builds the local part of a seeded user's identifier: prefix, dash, and the 1-based
     * index zero-padded to a width wide enough for the largest index (min 3), so a spec
     * can reconstruct every email without reading stdout.
     *
     * @param int $index 1-based user index
     * @param int $count Total users being seeded (sets the padding width)
     * @param string $prefix Identifier prefix
     * @return string Local part, e.g. `seed-001` (count 25) or `seed-0001` (count 1500)
     */
    public static function fixtureIdentifier(int $index, int $count, string $prefix): string
    {
        $width = max(self::MIN_SUFFIX_WIDTH, strlen((string)$count));

        return $prefix . '-' . str_pad((string)$index, $width, '0', STR_PAD_LEFT);
    }
}

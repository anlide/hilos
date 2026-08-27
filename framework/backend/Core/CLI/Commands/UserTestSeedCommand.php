<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;

/**
 * Test-only: bulk-seed N fixture users, each with an unverified password identity.
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
 * has no agent), mirroring the chat demo's `CreateOrphanCommand`. Strictly not idempotent: a
 * pre-existing identity for a generated email surfaces as a non-zero exit rather than a
 * silent skip, so a stale database (db:test:reset did not run) is caught, not masked.
 */
final class UserTestSeedCommand extends TestOnlyCommand
{
    /** @var string Truth-source id this CLI writer registers under (no agent runs in the CLI) */
    private const string TRUTH_SOURCE_ID = 'test-cli';

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
        return 'Bulk-seed N fixture users with password identities (test-only)';
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
generated identifier fails with a non-zero exit (a signal that db:test:reset did not
run). A project that does not implement the fixture-user seam is reported with a config
error, not a fatal.

Usage:
  php cli.php test:user:seed <count> [--prefix=<p>] [--password=<p>]

Arguments:
  <count>       Positive integer: how many users to seed

Options:
  --prefix=<p>   Identifier/name prefix (default: {$defaultPrefix})
  --password=<p> Shared plaintext password (default: matches the e2e helper's PASSWORD)

Examples:
  php cli.php test:user:seed 25
  php cli.php test:user:seed 200 --prefix=load --password=hunter2
HELP;
    }

    /**
     * Seeds `count` users, hashing the shared password once and reusing it for all.
     *
     * @param array<string, mixed> $options Parsed options: --prefix, --password
     * @param list<string> $args Positional args: [0] count
     * @return int Exit code (0 on success)
     * @throws EmptyValueException When a generated identifier is empty (unreachable: built here)
     * @throws DuplicateValueException When an identity for a generated email already exists
     * @throws DatabaseException When the user or identity write fails
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, checked on the very next line
        $countArg = $args[0] ?? '';
        if (preg_match('/^\d+$/', $countArg) !== 1 || (int)$countArg <= 0) {
            echo "Usage: {$this->getName()} <count> [--prefix=<p>] [--password=<p>]  (count: positive integer)\n";

            return ExitCode::INVALID_ARGUMENT;
        }
        $count = (int)$countArg;

        $prefix = $options['prefix'] ?? self::DEFAULT_PREFIX;
        if (!is_string($prefix) || $prefix === '') {
            echo "Option --prefix must be a non-empty string.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $password = $options['password'] ?? self::DEFAULT_PASSWORD;
        if (!is_string($password) || $password === '') {
            echo "Option --password must be a non-empty string.\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        // One bcrypt for the whole run: password_hash(PASSWORD_DEFAULT) is ~340ms and all
        // seeded users share the same password, so per-user hashing would dominate bring-up.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // The identities collection needs a registered writer; the CLI has no agent, so the
        // command registers itself as the truth source before mutating (test-only path).
        TruthSourceRegistry::register(HilosDbContext::identities, true, self::TRUTH_SOURCE_ID);

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

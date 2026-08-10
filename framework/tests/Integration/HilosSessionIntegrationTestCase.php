<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Hilos;

/**
 * Base for the session cases that need the framework session tables and a live Hilos::$db.
 *
 * The session carry-over (HIL-479) is a database mechanism end to end - it reads rows written
 * before a restore and writes rows into the database that replaced it - so it cannot be pinned
 * without a real one. This base raises the two framework tables it touches from their migration
 * stubs, mounts a framework database context over them, and takes both down again, so the shared
 * framework test database is left as it was found.
 */
abstract class HilosSessionIntegrationTestCase extends FrameworkIntegrationTestCase
{
    /**
     * @var list<string> Framework tables these cases need. `hilos_setting` is not read by the
     *     carry-over: it is the one framework collection loaded eagerly, so a re-hydration - the
     *     very thing a restore triggers - reaches for it whether the case cares about it or not.
     */
    private const array TABLES = ['hilos_session', 'hilos_identity', 'hilos_setting'];

    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws ObjectCollectionNotFoundException When a framework object collection is missing
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $db = new HilosSessionTestDbContext();
        $db->configure();
        Hilos::$db = $db;
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * Inserts an identity row the way the auth layer would have.
     *
     * @param int $userId Owning user id
     * @param string $type Identity type
     * @param string $identifier Normalized identifier
     * @throws DatabaseException When the insert fails
     */
    protected static function seedIdentity(int $userId, string $type, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`, `verified`) VALUES (?, ?, ?, 1)',
            [$userId, $type, $identifier],
        );
    }

    /**
     * Inserts a session row with an explicit lifetime.
     *
     * @param string $token Session cookie token
     * @param ?int $userId Bound user id, or null for an anonymous session
     * @param string $createdAt Creation time as an SQL datetime
     * @param ?string $expiresAt Expiry as an SQL datetime, or null for an open-ended session
     * @param ?int $impersonatorUserId Real administrator behind a takeover, or null when there is none
     * @throws DatabaseException When the insert fails
     */
    protected static function seedSession(
        string $token,
        ?int $userId,
        string $createdAt,
        ?string $expiresAt,
        ?int $impersonatorUserId = null,
    ): void {
        Database::sqlRun(
            'INSERT INTO `hilos_session` '
            . '(`token`, `user_id`, `impersonator_user_id`, `created_at`, `last_seen_at`, `expires_at`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [$token, $userId, $impersonatorUserId, $createdAt, $createdAt, $expiresAt],
        );
    }

    /**
     * Reads a session straight from the database, past every in-memory collection.
     *
     * @param string $token Session cookie token
     * @return ?array<string, mixed> Raw session row, or null when the token holds none
     * @throws DatabaseException When the query fails
     */
    protected static function sessionRow(string $token): ?array
    {
        Database::sql(
            'SELECT `user_id`, `impersonator_user_id`, `created_at`, `last_seen_at`, `expires_at` '
            . 'FROM `hilos_session` WHERE `token` = ?',
            [$token],
        );

        return Database::row();
    }

    /**
     * Runs one direction of the stub file of every table these cases use.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The carry-over is framework-owned and reads only framework tables, so the smallest honest
 * context for it is {@see HilosDbContext} with no project collections added.
 */
final class HilosSessionTestDbContext extends HilosDbContext
{
}

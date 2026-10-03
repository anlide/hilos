<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The framework's person keys refuse dead references and preserve a file past its owner.
 */
final class PersonForeignKeysIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user',
        'hilos_identity',
        'hilos_session',
        'hilos_notification',
        'hilos_file',
    ];

    /**
     * @throws DatabaseException When a stub cannot be applied
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::runStubs(down: true);
        self::runStubs(down: false);
    }

    /**
     * @throws DatabaseException When a stub cannot be dropped
     */
    protected function tearDown(): void
    {
        self::runStubs(down: true);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, list<int|string>}> Invalid child statements and their parameters
     */
    public static function rowsAboutNobody(): iterable
    {
        yield 'identity' => [
            'INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`) VALUES (?, ?, ?)',
            [999, 'password', 'nobody@example.test'],
        ];
        yield 'session' => [
            'INSERT INTO `hilos_session` (`token`, `user_id`) VALUES (?, ?)',
            ['ffffffffffffffffffffffffffffffff', 999],
        ];
        yield 'notification' => [
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (?, ?, ?)',
            [999, 'account.test', 'Nobody'],
        ];
    }

    /**
     * @param string $sql Child insert statement
     * @param list<int|string> $params Inserted values
     * @throws DatabaseException Expected refusal for a missing person
     */
    #[DataProvider('rowsAboutNobody')]
    public function testAChildOfNobodyIsRefused(string $sql, array $params): void
    {
        $this->expectException(DatabaseException::class);
        Database::sqlRun($sql, $params);
    }

    /**
     * @throws DatabaseException Expected refusal while a notification remains
     */
    public function testAReferencedPersonCannotBeDeleted(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Recipient')");
        Database::sqlRun(
            'INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (7, ?, ?)',
            ['account.test', 'Still here'],
        );

        $this->expectException(DatabaseException::class);
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = 7');
    }

    /**
     * @throws DatabaseException When a row cannot be written or read
     */
    public function testAFileOutlivesItsOwnerWithAnEmptyOwner(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Owner')");
        Database::sqlRun(
            'INSERT INTO `hilos_file` '
            . '(`stored_name`, `filename`, `mime_type`, `size`, `content_hash`, `owner_user_id`, `visibility`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['stored.txt', 'name.txt', 'text/plain', 1, str_repeat('a', 64), 7, 'owner'],
        );

        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = 7');
        Database::sql('SELECT `owner_user_id` FROM `hilos_file` WHERE `stored_name` = ?', ['stored.txt']);
        $row = Database::row();
        self::assertNotNull($row, 'SET NULL keeps the file row');
        self::assertNull($row['owner_user_id']);
    }

    /**
     * @param bool $down Whether to drop rather than create the tables
     * @throws DatabaseException When a stub fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the create stub carries no filename suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

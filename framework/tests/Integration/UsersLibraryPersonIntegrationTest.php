<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * The person as the framework's users library creates, names and guards it (HIL-1194, HIL-1199).
 *
 * The library under test is the base class with nothing overridden, so what answers is the
 * framework's own body over `hilos_user`, and no project stands between the two. Every write
 * runs in the library's own frame and under the claim the library itself declares: in a frame
 * a write passes only by that agent's own grant, which is what proves the base holds the row.
 */
final class UsersLibraryPersonIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const int MISSING_USER_ID = 9999;

    private UsersLibraryPersonTestLibrary $library;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->library = new UsersLibraryPersonTestLibrary();
        OwnershipDeclaration::claimDb($this->library::class, $this->library->getId());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent($this->library->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->library->getId()));

        parent::tearDown();
    }

    /**
     * @throws HilosException When the person cannot be created or read
     */
    public function testCreatesThePersonInTheFrameworkTableAndNamesThem(): void
    {
        $userId = $this->inLibrary(fn (): int => $this->library->createUser('  Ada  '));

        self::assertGreaterThan(0, $userId);
        Database::sql('SELECT `name`, `admin`, `block`, `last_activity` FROM `hilos_user` WHERE `id` = ?', [$userId]);
        $row = Database::row();
        self::assertNotNull($row);
        self::assertSame('Ada', $row['name']);
        self::assertSame(0, (int)$row['admin']);
        self::assertSame(0, (int)$row['block']);
        self::assertNotNull($row['last_activity']);
        self::assertSame('Ada', $this->library->displayNameOf($userId));
    }

    /**
     * @throws HilosException When the table cannot be counted
     */
    public function testRefusesAPersonWithABlankName(): void
    {
        try {
            $this->inLibrary(fn (): int => $this->library->createUser('   '));
            self::fail('A blank name must not create a person');
        } catch (EmptyValueException $e) {
            self::assertSame('User name cannot be empty', $e->getMessage());
        }

        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_user`');
        self::assertSame(0, (int)Database::row()['count']);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or read
     */
    public function testNamesNobodyForAMissingOrUnnamedPerson(): void
    {
        self::assertNull($this->library->displayNameOf(self::MISSING_USER_ID));

        $unnamedId = self::seedPerson('', admin: false);
        self::assertNull($this->library->displayNameOf($unnamedId));
    }

    /**
     * @throws HilosException When the check cannot read the person
     */
    public function testRefusesToDeleteAMissingPerson(): void
    {
        $this->expectException(ItemNotFoundForUpdateException::class);
        $this->expectExceptionMessage('No such user: ' . self::MISSING_USER_ID);

        $this->library->assertMayDelete(self::MISSING_USER_ID);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesToDeleteAnAdministrator(): void
    {
        $adminId = self::seedPerson('Root', admin: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Remove the admin rights first');

        $this->library->assertMayDelete($adminId);
    }

    /**
     * An account folded into another one is not scheduled for deletion by an administrator; an
     * administrator that was folded is refused as an administrator first.
     *
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testRefusesToDeleteAMergedAccountAfterAnAdministrator(): void
    {
        $survivorId = self::seedPerson('Survivor', admin: false);
        $foldedId = self::seedPerson('Folded', admin: false);
        $foldedAdminId = self::seedPerson('Folded root', admin: true);
        foreach ([$foldedId, $foldedAdminId] as $userId) {
            Database::sqlRun(
                'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NOW())',
                [$userId, $survivorId],
            );
        }

        foreach ([
            $foldedId => AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE,
            $foldedAdminId => 'Remove the admin rights first',
        ] as $userId => $refusal) {
            try {
                $this->library->assertMayDelete($userId);
                self::fail('A merged account must not be scheduled for deletion');
            } catch (ValidationException $e) {
                self::assertSame($refusal, $e->getMessage());
            }
        }

        $this->library->assertMayDelete($survivorId);
    }

    /**
     * @throws HilosException When a fixture row cannot be written or the check cannot read it
     */
    public function testLetsAnOrdinaryPersonBeDeleted(): void
    {
        $userId = self::seedPerson('Grace', admin: false);

        $this->library->assertMayDelete($userId);

        $this->addToAssertionCount(1);
    }

    /**
     * Runs one step in the library's own frame, the way its worker would.
     *
     * @template T
     * @param callable(): T $step Step to run as the library
     * @return T Whatever the step returns
     */
    private function inLibrary(callable $step): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->library->getId()), $step);
    }

    /**
     * Inserts a person row past every action, so the check reads a state no action would write.
     *
     * @param string $name Name the row carries, empty included
     * @param bool $admin Whether the row is an administrator
     * @return int Id of the inserted row
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO `hilos_user` (`name`, `admin`) VALUES (?, ?)', [$name, (int)$admin]);

        return Database::lastInsertId();
    }
}

/**
 * The framework's users library under a test name, with no method of the person overridden.
 */
final class UsersLibraryPersonTestLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'integration_users_library_person';

    /**
     * Opens the protected deletion check to the test.
     *
     * @param int $userId Account an administrator wants to schedule for deletion
     * @throws HilosException Whatever the framework's check raises
     */
    public function assertMayDelete(int $userId): void
    {
        $this->assertAdministratorMayDelete($userId);
    }
}

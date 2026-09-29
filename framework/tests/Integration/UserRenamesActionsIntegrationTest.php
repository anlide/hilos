<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Item\UserRenameActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserRename as EntityUserRename;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * The framework rename journal against the live table (HIL-1195).
 *
 * HIL-1112: the journal is cut into sets by the renamed person, and the owner of one
 * person's set creates that person's rows and no one else's. The other cases write as the
 * owner of the whole journal, which is what the users library is. The people are seeded past
 * every action: they are the ground the journal stands on, not what is under test.
 */
final class UserRenamesActionsIntegrationTest extends HilosSessionIntegrationTestCase
{
    /** Agent that holds the rename journal of one person. */
    private const string SET_OWNER_AGENT_ID = 'test-agent:set-owner';

    /** Agent that holds the whole rename journal, the way the users library does. */
    private const string JOURNAL_OWNER_AGENT_ID = 'test-agent:journal-owner';

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::SET_OWNER_AGENT_ID);
        TruthSourceRegistry::unregisterAgent(self::JOURNAL_OWNER_AGENT_ID);

        parent::tearDown();
    }

    /**
     * The owner of a person's set writes that person's rename, and is refused another person's.
     *
     * @throws HilosException On database error
     */
    public function testTheSetOwnerCreatesARowOfItsSetAndNotOfAnother(): void
    {
        $own = self::seedPerson('Own');
        $other = self::seedPerson('Other');

        TruthSourceRegistry::register(HilosDbContext::userRenames, TruthSourceKeys::set((string)$own), self::SET_OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::SET_OWNER_AGENT_ID);

        $rename = Hilos::$db->userRenames->actions->add($own, $other, 'Old name', 'New name');
        $this->assertSame($own, $rename->userId);
        $this->assertSame($other, $rename->renamedByUserId);
        $this->assertSame('Old name', $rename->oldName);
        $this->assertSame('New name', $rename->newName);

        try {
            Hilos::$db->userRenames->actions->add($other, $own, 'Old name', 'New name');
            $this->fail('Expected a rename of another person to be refused');
        } catch (CreateNotAllowedException $e) {
            $this->assertStringContainsString("it holds set '{$own}'", $e->getMessage());
        }

        $this->assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $other]));
    }

    /**
     * A row written with no author keeps the author empty - the rename was not a person's.
     *
     * @throws HilosException On database error
     */
    public function testARenameWithNoAuthorIsStoredWithAnEmptyAuthor(): void
    {
        $person = self::seedPerson('Person');
        self::ownTheJournal();

        $rename = Hilos::$db->userRenames->actions->add($person, null, 'Old name', 'New name');

        $this->assertNull($rename->renamedByUserId);
        Database::sql('SELECT `renamed_by_user_id` FROM `hilos_user_rename` WHERE `id` = ?', [$rename->id]);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('renamed_by_user_id', $row);
        $this->assertNull($row['renamed_by_user_id']);
    }

    /**
     * One person's rows are read out of the database, a row this process never held included,
     * and nobody else's rows come with them.
     *
     * @throws HilosException On database error
     */
    public function testByUserReadsTheWholeSetOfOnePerson(): void
    {
        $person = self::seedPerson('Person');
        $other = self::seedPerson('Other');
        self::ownTheJournal();
        Hilos::$db->userRenames->actions->add($person, $other, 'First', 'Second');
        Database::sqlRun(
            'INSERT INTO `hilos_user_rename` (`user_id`, `renamed_by_user_id`, `old_name`, `new_name`, `renamed_at`) '
            . "VALUES (?, NULL, 'Second', 'Third', '2026-01-01 00:00:00'), (?, ?, 'Other', 'Another', '2026-01-01 00:00:00')",
            [$person, $other, $person],
        );

        $names = [];
        foreach (Hilos::$db->userRenames->byUser($person) as $rename) {
            $this->assertSame($person, $rename->userId);
            $names[] = $rename->newName;
        }
        sort($names);

        $this->assertSame(['Second', 'Third'], $names);
    }

    /**
     * An erasure takes the person's own rows; a row where the person is only the author stays.
     *
     * @throws HilosException On database error
     */
    public function testDeleteByUserTakesThePersonsRowsAndLeavesTheOnesTheyAuthored(): void
    {
        $person = self::seedPerson('Person');
        $other = self::seedPerson('Other');
        self::ownTheJournal();
        Hilos::$db->userRenames->actions->add($person, $other, 'First', 'Second');
        Hilos::$db->userRenames->actions->add($person, $person, 'Second', 'Third');
        $authored = Hilos::$db->userRenames->actions->add($other, $person, 'Other', 'Another');

        $deleted = Hilos::$db->userRenames->actions->deleteByUser($person);

        $this->assertSame(2, $deleted);
        $this->assertCount(0, EntityUserRename::get([EntityUserRename::user_id => $person]));
        $this->assertCount(0, Hilos::$db->userRenames->byUser($person));
        $left = EntityUserRename::get([EntityUserRename::user_id => $other]);
        $this->assertCount(1, $left);
        $this->assertSame($authored->id, $left->first()?->id);
    }

    /**
     * A journal row carries the framework's item actions: empty, and the door a project that
     * extended the journal with a column of its own writes that column through (HIL-1196).
     *
     * @throws HilosException On database error
     */
    public function testARowCarriesTheItemActionsAProjectExtends(): void
    {
        $person = self::seedPerson('Person');
        self::ownTheJournal();

        $rename = Hilos::$db->userRenames->actions->add($person, $person, 'Old name', 'New name');

        $this->assertInstanceOf(UserRenameActions::class, $rename->actions);
    }

    /**
     * Writes from here on as the owner of the whole journal.
     */
    private static function ownTheJournal(): void
    {
        TruthSourceRegistry::register(HilosDbContext::userRenames, TruthSourceKeys::all(), self::JOURNAL_OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::JOURNAL_OWNER_AGENT_ID);
    }

    /**
     * @param string $name Name the row carries
     * @return int Id of the inserted person
     * @throws DatabaseException When the insert fails
     */
    private static function seedPerson(string $name): int
    {
        Database::sql('INSERT INTO `hilos_user` (`name`) VALUES (?)', [$name]);

        return Database::lastInsertId();
    }
}

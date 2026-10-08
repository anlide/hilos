<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * The framework merge table against the live table (HIL-1199).
 *
 * Every case writes as the owner of the whole table, which is what the sessions library is.
 * The people are seeded past every action: they are the ground the merges stand on, not what
 * is under test. The keys onto `hilos_user` are asked of the database itself, by deleting a
 * person's row with plain SQL.
 */
final class UserMergesActionsIntegrationTest extends HilosSessionIntegrationTestCase
{
    /** Agent that holds the whole merge table, the way the sessions library does. */
    private const string MERGE_OWNER_AGENT_ID = 'test-agent:merge-owner';

    protected function setUp(): void
    {
        parent::setUp();

        TruthSourceRegistry::register(HilosDbContext::userMerges, TruthSourceKeys::all(), self::MERGE_OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::MERGE_OWNER_AGENT_ID);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::MERGE_OWNER_AGENT_ID);

        parent::tearDown();
    }

    /**
     * A merge is written with its moment, and the folded account is found by its own key.
     *
     * @throws HilosException On database error
     */
    public function testAMergeIsRecordedWithItsMomentAndFoundByTheFoldedAccount(): void
    {
        $survivor = self::seedPerson('Survivor');
        $loser = self::seedPerson('Loser');

        $merge = Hilos::$db->userMerges->actions->add($loser, $survivor);

        $this->assertSame($loser, $merge->userId);
        $this->assertSame($survivor, $merge->survivorUserId);
        $this->assertNotNull($merge->mergedAt);
        Database::sql('SELECT `survivor_user_id`, `merged_at` FROM `hilos_user_merge` WHERE `user_id` = ?', [$loser]);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame($survivor, (int)$row['survivor_user_id']);
        $this->assertNotNull($row['merged_at']);

        $this->assertSame($survivor, Hilos::$db->userMerges[$loser]?->survivorUserId);
        $this->assertNull(Hilos::$db->userMerges[$survivor]);
    }

    /**
     * A row this process never held is found by key too: "is this account merged" is asked in
     * other processes than the one that merged.
     *
     * @throws HilosException On database error
     */
    public function testAMergeWrittenElsewhereIsFoundByKey(): void
    {
        $survivor = self::seedPerson('Survivor');
        $loser = self::seedPerson('Loser');
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NULL)',
            [$loser, $survivor],
        );

        $merge = Hilos::$db->userMerges[$loser];

        $this->assertNotNull($merge);
        $this->assertSame($survivor, $merge->survivorUserId);
        $this->assertNull($merge->mergedAt);
    }

    /**
     * An account is folded at most once: a second merge of it is refused by the database.
     *
     * @throws HilosException On database error
     */
    public function testASecondMergeOfTheSameAccountIsRefused(): void
    {
        $survivor = self::seedPerson('Survivor');
        $third = self::seedPerson('Third');
        $loser = self::seedPerson('Loser');
        Hilos::$db->userMerges->actions->add($loser, $survivor);

        try {
            Hilos::$db->userMerges->actions->add($loser, $third);
            $this->fail('Expected a second merge of the same account to be refused');
        } catch (DatabaseException) {
            // The key of the row is the folded account
        }

        $rows = EntityUserMerge::get([EntityUserMerge::user_id => $loser]);
        $this->assertCount(1, $rows);
        $this->assertSame($survivor, $rows->first()?->survivor_user_id);
    }

    /**
     * The accounts folded into one person are read out of the database, a row this process
     * never held included, and nobody else's come with them.
     *
     * @throws HilosException On database error
     */
    public function testFoldedIntoReadsEveryAccountFoldedIntoOnePerson(): void
    {
        $survivor = self::seedPerson('Survivor');
        $other = self::seedPerson('Other');
        $first = self::seedPerson('First');
        $second = self::seedPerson('Second');
        $elsewhere = self::seedPerson('Elsewhere');
        Hilos::$db->userMerges->actions->add($first, $survivor);
        Hilos::$db->userMerges->actions->add($elsewhere, $other);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NULL)',
            [$second, $survivor],
        );

        $folded = [];
        foreach (Hilos::$db->userMerges->foldedInto($survivor) as $merge) {
            $this->assertSame($survivor, $merge->survivorUserId);
            $folded[] = $merge->userId;
        }
        sort($folded);

        $this->assertSame([$first, $second], $folded);
    }

    /**
     * The live end of a chain is the first account up it with no merge row of its own (HIL-1292):
     * an account never folded has no end to speak of, a straight merge ends at its survivor, and
     * a chain of merges ends at the last survivor, read row by row.
     *
     * @throws HilosException On database error
     */
    public function testLiveSurvivorOfWalksTheChainToItsLiveEnd(): void
    {
        $first = self::seedPerson('First');
        $second = self::seedPerson('Second');
        $third = self::seedPerson('Third');
        $alone = self::seedPerson('Alone');
        Hilos::$db->userMerges->actions->add($first, $second);

        $this->assertNull(Hilos::$db->userMerges->liveSurvivorOf($alone));
        $this->assertSame($second, Hilos::$db->userMerges->liveSurvivorOf($first));

        Hilos::$db->userMerges->actions->add($second, $third);

        $this->assertSame($third, Hilos::$db->userMerges->liveSurvivorOf($first));
        $this->assertSame($third, Hilos::$db->userMerges->liveSurvivorOf($second));
        $this->assertNull(Hilos::$db->userMerges->liveSurvivorOf($third));
    }

    /**
     * A chain that names no live account has no end to point at: a survivor erased before HIL-1200
     * left its row pointing at nobody, and rows closing a loop lead back to where they started.
     *
     * @throws HilosException On database error
     */
    public function testLiveSurvivorOfAnswersNullWhenTheChainLeadsNowhere(): void
    {
        $orphan = self::seedPerson('Orphan');
        $loopA = self::seedPerson('LoopA');
        $loopB = self::seedPerson('LoopB');
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, NULL, NULL)',
            [$orphan],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, NULL), (?, ?, NULL)',
            [$loopA, $loopB, $loopB, $loopA],
        );

        $this->assertNull(Hilos::$db->userMerges->liveSurvivorOf($orphan));
        $this->assertNull(Hilos::$db->userMerges->liveSurvivorOf($loopA));
        $this->assertNull(Hilos::$db->userMerges->liveSurvivorOf($loopB));
    }

    /**
     * The erasure of a folded account takes its merge row; an account never folded has none,
     * which is not an error.
     *
     * @throws HilosException On database error
     */
    public function testDeleteForUserTakesTheRowOfTheFoldedAccount(): void
    {
        $survivor = self::seedPerson('Survivor');
        $loser = self::seedPerson('Loser');
        Hilos::$db->userMerges->actions->add($loser, $survivor);

        Hilos::$db->userMerges->actions->deleteForUser($loser);
        Hilos::$db->userMerges->actions->deleteForUser($survivor);

        $this->assertCount(0, EntityUserMerge::get([EntityUserMerge::user_id => $loser]));
        $this->assertNull(Hilos::$db->userMerges[$loser]);
        // Nothing holds the person's row any more
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$loser]);
    }

    /**
     * The survivor's row goes and the folded account stays folded, with nobody to point at.
     *
     * @throws HilosException On database error
     */
    public function testErasingTheSurvivorLeavesTheFoldedAccountFolded(): void
    {
        $survivor = self::seedPerson('Survivor');
        $loser = self::seedPerson('Loser');
        Hilos::$db->userMerges->actions->add($loser, $survivor);

        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$survivor]);

        Database::sql('SELECT `survivor_user_id` FROM `hilos_user_merge` WHERE `user_id` = ?', [$loser]);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('survivor_user_id', $row);
        $this->assertNull($row['survivor_user_id']);
    }

    /**
     * The folded account's row cannot go while its merge row stands: forgetting the merge row
     * stops an erasure loudly instead of leaving it behind.
     *
     * @throws HilosException On database error
     */
    public function testTheFoldedAccountCannotBeDeletedPastItsMergeRow(): void
    {
        $survivor = self::seedPerson('Survivor');
        $loser = self::seedPerson('Loser');
        Hilos::$db->userMerges->actions->add($loser, $survivor);

        try {
            Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [$loser]);
            $this->fail('Expected the delete of a folded account past its merge row to be refused');
        } catch (DatabaseException) {
            // RESTRICT on the folded account
        }

        Database::sql('SELECT COUNT(*) AS `n` FROM `hilos_user` WHERE `id` = ?', [$loser]);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame(1, (int)$row['n']);
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

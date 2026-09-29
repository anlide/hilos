<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosMergeCandidateTableRow;
use Hilos\Tables\Users\HilosUserTableRow;

/**
 * The framework window of merge candidates over the framework's own tables (HIL-411, HIL-1201).
 *
 * The window reads the people, their sign-in methods, and the merges itself, so the cases seed
 * all three and read the window back through the table. Every case writes merges as the owner
 * of the whole merge table, which is what the sessions library is. The people carry fixed ids:
 * the exact-id search is only told apart from a substring match by an id that contains another.
 */
final class HilosMergeCandidatesTableIntegrationTest extends HilosSessionIntegrationTestCase
{
    /** Agent that holds the whole merge table, the way the sessions library does. */
    private const string MERGE_OWNER_AGENT_ID = 'test-agent:merge-owner';

    private const int ALPHA = 1;

    private const int BETA = 2;

    private const int GAMMA = 3;

    private const int TWELVE = 12;

    private const string PASSWORD_TYPE = 'password';

    private const string LINK_TYPE = 'magic_link';

    private const string BETA_EMAIL = 'beta@example.test';

    private const string BETA_SECOND_EMAIL = 'beta.second@example.test';

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
     * Neither the survivor nor an account already folded into another one is offered.
     *
     * @throws HilosException On database error
     */
    public function testTheSurvivorAndAFoldedAccountAreAbsent(): void
    {
        $this->seedPeople();
        Hilos::$db->userMerges->actions->add(self::GAMMA, self::ALPHA);

        $snapshot = $this->table()->getPage(new TableQueryDTO(
            filter: [HilosMergeCandidatesTable::FILTER_SURVIVOR => self::ALPHA],
        ));

        $this->assertSame([self::BETA, self::TWELVE], self::rowKeys($snapshot->rows));
    }

    /**
     * @throws HilosException On database error
     */
    public function testSearchMatchesASignInAddressSubstring(): void
    {
        $this->seedPeople();

        $snapshot = $this->table()->getPage(new TableQueryDTO(search: 'beta@'));

        $this->assertSame([self::BETA], self::rowKeys($snapshot->rows));
    }

    /**
     * @throws HilosException On database error
     */
    public function testANumericSearchMatchesOnlyTheExactId(): void
    {
        $this->seedPeople();

        $snapshot = $this->table()->getPage(new TableQueryDTO(search: (string) self::TWELVE));

        $this->assertSame([self::TWELVE], self::rowKeys($snapshot->rows));
    }

    /**
     * The person rides the users slot without presence, and the sign-in methods ride the merge slot without a secret.
     *
     * @throws HilosException On database error
     */
    public function testBrowserRowSplitsTheUserAndMergeSlotsWithoutASecret(): void
    {
        $this->seedPeople();
        $table = $this->table();

        $row = $table->getPage(new TableQueryDTO(search: 'beta@'))->rows[0];
        $sources = $table->browserRow($row)[BrowserPageSignalData::sources];

        $this->assertSame(
            [
                HilosUserTableRow::id => self::BETA,
                HilosUserTableRow::admin => false,
                HilosUserTableRow::block => false,
                HilosUserTableRow::name => 'Beta',
                HilosUserTableRow::lastActivity => null,
            ],
            $sources[HilosMergeCandidatesTable::SLOT_USER],
        );
        $this->assertTrue($sources[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
        $identity = $sources[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_IDENTITIES][0];
        $this->assertSame(self::BETA_EMAIL, $identity[ObjectIdentity::identifier]);
        $this->assertArrayNotHasKey('secret', $identity);
    }

    /**
     * The envelope of a candidate row keeps the user fields and the merge fields in separate slots.
     */
    public function testACandidateRowKeepsTheUserAndTheMergeFieldsInSeparateSlots(): void
    {
        $identity = [
            ObjectIdentity::type => 'email',
            ObjectIdentity::identifier => 'loser@example.test',
            ObjectIdentity::provider => null,
            ObjectIdentity::verified => true,
        ];
        $userFields = [
            HilosUserTableRow::id => 7,
            HilosUserTableRow::admin => false,
            HilosUserTableRow::block => false,
            HilosUserTableRow::name => 'Loser',
            HilosUserTableRow::lastActivity => null,
        ];

        $this->assertSame(
            [
                BrowserPageSignalData::rowKey => 7,
                BrowserPageSignalData::sources => [
                    HilosMergeCandidatesTable::SLOT_USER => $userFields,
                    HilosMergeCandidatesTable::SLOT_MERGE => [
                        HilosMergeCandidatesTable::FIELD_IDENTITIES => [$identity],
                        HilosMergeCandidatesTable::FIELD_HAS_PASSWORD => true,
                    ],
                ],
            ],
            $this->table()->browserRow(new HilosMergeCandidateTableRow(
                userFields: $userFields,
                identities: [$identity],
                hasPassword: true,
            )),
        );
    }

    /**
     * A change of a sign-in method that names only itself is traced to its owner, whose row is refreshed.
     *
     * @throws HilosException On database error
     */
    public function testAnIdentityChangeRefreshesItsOwnersCandidateRow(): void
    {
        $this->seedPeople();
        self::seedIdentity(self::BETA, self::LINK_TYPE, self::BETA_SECOND_EMAIL);
        Database::sql('SELECT `id` FROM `hilos_identity` WHERE `identifier` = ?', [self::BETA_SECOND_EMAIL]);
        $identity = Database::row();
        $this->assertNotNull($identity);
        $identityId = (int) $identity['id'];

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::identities, (string) $identityId, [ObjectIdentity::identifier => 'changed']),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame(self::BETA, $mutation->rowKey);
    }

    /**
     * A person whose row changed after the merge is gone from the window.
     *
     * @throws HilosException On database error
     */
    public function testAPersonThatBecameFoldedIsDeletedFromTheWindow(): void
    {
        $this->seedPeople();
        Hilos::$db->userMerges->actions->add(self::GAMMA, self::ALPHA);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::users, (string) self::GAMMA, [HilosUserTableRow::block => true]),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame(self::GAMMA, $mutation->rowKey);
    }

    /**
     * An account blocked before it was folded takes no change of its own row, so the merge row takes it out (P-449).
     *
     * @throws HilosException On database error
     */
    public function testTheMergeRowOfAnAlreadyBlockedAccountTakesItOutOfTheWindow(): void
    {
        $this->seedPeople();
        Database::sqlRun('UPDATE `hilos_user` SET `block` = 1 WHERE `id` = ?', [self::GAMMA]);
        Hilos::$db->userMerges->actions->add(self::GAMMA, self::ALPHA);

        $mutation = $this->table()->buildMutationForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::userMerges,
            (string) self::GAMMA,
            [ObjectUserMerge::userId => self::GAMMA, ObjectUserMerge::survivorUserId => self::ALPHA],
        ));

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Delete, $mutation->type);
        $this->assertSame(self::GAMMA, $mutation->rowKey);
        $this->assertNull($mutation->row);
    }

    /**
     * A merge row goes only with the folded account itself, whose person row leaves through the people source.
     *
     * @throws HilosException On database error
     */
    public function testRemovingAMergeRowLeavesTheWindowAlone(): void
    {
        $this->assertNull($this->table()->buildMutationForSourceEvent(
            SourceChange::dbDeleted(HilosDbContext::userMerges, (string) self::GAMMA),
        ));
    }

    /**
     * @return HilosMergeCandidatesTable The framework window, as a project registers it
     */
    private function table(): HilosMergeCandidatesTable
    {
        return new HilosMergeCandidatesTable();
    }

    /**
     * Seeds four people, each with one sign-in address; Beta's is a password.
     *
     * @throws DatabaseException When an insert fails
     */
    private function seedPeople(): void
    {
        foreach ([
            self::ALPHA => ['Alpha', self::LINK_TYPE, 'alpha@example.test'],
            self::BETA => ['Beta', self::PASSWORD_TYPE, self::BETA_EMAIL],
            self::GAMMA => ['Gamma', self::LINK_TYPE, 'gamma@example.test'],
            self::TWELVE => ['Twelve', self::LINK_TYPE, 'twelve@example.test'],
        ] as $id => [$name, $type, $identifier]) {
            Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?)', [$id, $name]);
            self::seedIdentity($id, $type, $identifier);
        }
    }

    /**
     * @param list<AbstractTableRow> $rows Rows of a window
     * @return list<int|string> Their keys, in the window's order
     */
    private static function rowKeys(array $rows): array
    {
        return array_map(static fn(AbstractTableRow $row): int|string => $row->getRowKey(), $rows);
    }
}

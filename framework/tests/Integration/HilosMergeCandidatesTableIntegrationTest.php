<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountOperationCircle;
use Hilos\Auth\AccountOperation\AccountOperationOutcome;
use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;
use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;
use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;
use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;
use Hilos\Database\Entity\Item\UserMerge as EntityUserMerge;
use Hilos\Database\Object\Item\AccountOperation as ObjectAccountOperation;
use Hilos\Database\Object\Item\AccountOperationAssignment as ObjectAccountOperationAssignment;
use Hilos\Database\Object\Item\AccountOperationResult as ObjectAccountOperationResult;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\Users\AccountMergeAvailabilityResolver;
use Hilos\Users\AccountMergeSourceChange;
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
use Hilos\Database\Object\Item\AccountDeletion as ObjectAccountDeletion;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\Object\Item\UserMerge as ObjectUserMerge;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosMergeCandidateTableRow;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\Utils\Helpers\TimeHelper;

require_once __DIR__ . '/MergeTableOwnerFrames.php';

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
    use MergeTableOwnerFrames;

    public function testReservedCandidateStaysInItsWindowAndMetadataKeysReachBothSides(): void
    {
        TruthSourceRegistry::unregisterAgent(self::MERGE_OWNER_AGENT_ID);
        $this->withMergeOwners(function (): void {
            $this->seedPeople();
            $operation = $this->openTableMerge(self::ALPHA, self::BETA);
            $table = $this->table();
            $query = new TableQueryDTO(filter: [HilosMergeCandidatesTable::FILTER_SURVIVOR => self::ALPHA]);
            $before = $table->getPage($query);
            $asks = $this->mergeFrames();
            $this->deliverMergeFrame($asks[1]);
            $result = Hilos::$db->accountOperationResults->byAssignment($asks[1]->data->data->assignmentId);
            $after = $table->getPage($query);
            self::assertSame(self::rowKeys($before->rows), self::rowKeys($after->rows));
            self::assertSame($before->totalCount, $after->totalCount);
            self::assertTrue($table->containsRow(self::BETA, $query));
            $wire = $table->browserRow($after->rows[0])[BrowserPageSignalData::sources];
            self::assertSame(['reserved' => true, 'available' => false], $wire['merge']['accountMergeAvailability']);
            self::assertArrayNotHasKey('accountMergeAvailability', $wire['users']);
            self::assertSame(['identities', 'hasPassword', 'hasSecondFactor', 'unverifiedPasswordAddress', 'accountMergeAvailability'],
                array_keys($wire['merge']));
            foreach ([
                SourceChange::dbUpdated(HilosDbContext::accountOperations, (string) $operation->id, []),
                SourceChange::dbUpdated(HilosDbContext::accountOperationAssignments, (string) $result->assignmentId, []),
                SourceChange::dbCreated(HilosDbContext::accountOperationResults, (string) $result->id, []),
            ] as $event) {
                $mutations = $table->buildMutationsForSourceEvent($event);
                self::assertEqualsCanonicalizing([self::ALPHA, self::BETA], array_column($mutations, 'rowKey'));
                self::assertCount(2, $mutations);
            }
            $other = $this->openTableMerge(self::GAMMA, self::TWELVE);
            $otherAssignment = Hilos::$db->accountOperationAssignments->forOperation($other->id)->first();
            self::assertEqualsCanonicalizing([1, 2, 3, 12], AccountMergeSourceChange::userIds(SourceChange::dbUpdated(
                HilosDbContext::accountOperationAssignments, (string) $result->assignmentId, [],
                previous: [ObjectAccountOperationAssignment::operationId => $other->id])));
            self::assertEqualsCanonicalizing([1, 2, 3, 12], AccountMergeSourceChange::userIds(SourceChange::dbUpdated(
                HilosDbContext::accountOperationResults, (string) $result->id, [],
                previous: [ObjectAccountOperationResult::assignmentId => $otherAssignment->id])));
            self::assertEqualsCanonicalizing([1, 2, 3, 12], AccountMergeSourceChange::userIds(SourceChange::dbUpdated(
                HilosDbContext::accountOperations, (string) $operation->id, [],
                previous: [ObjectAccountOperation::rootUserId => self::TWELVE, ObjectAccountOperation::loserUserId => self::GAMMA])));
            self::assertNotNull(Database::sql('SELECT `id` FROM `hilos_account_operation_result` WHERE `id` = ?', [$result->id])->row());
            foreach ([HilosDbContext::accountOperations, HilosDbContext::accountOperationAssignments,
                HilosDbContext::accountOperationResults, HilosDbContext::accountDeletions, HilosDbContext::userMerges] as $source) {
                self::assertSame([1, 2, 3, 12], AccountMergeSourceChange::userIds(SourceChange::dbCleared($source)));
            }
            self::assertSame([1, 2, 3, 12], AccountMergeSourceChange::userIds(
                SourceChange::dbDeleted(HilosDbContext::accountOperationResults, '9999')));
            self::assertSame([1, 2, 3, 12], AccountMergeSourceChange::userIds(SourceChange::dbDeleted(
                HilosDbContext::accountOperationAssignments, '9999', [ObjectAccountOperationAssignment::operationId => 9999])));
            self::assertSame([1, 2, 3, 12], array_column($table->buildMutationsForSourceEvent(
                SourceChange::dbCleared(HilosDbContext::accountOperationResults)), 'rowKey'));
        });
    }

    /** Both carrier shapes retain row/previous relations, with fallback reserved for incomplete facts. */
    public function testMetadataEntityAndObjectRowsPreserveRelationsAndConservativeFallbacks(): void
    {
        TruthSourceRegistry::unregisterAgent(self::MERGE_OWNER_AGENT_ID);
        $this->withMergeOwners(function (): void {
            $this->seedPeople();
            Database::sqlRun('INSERT INTO hilos_user (id, name) VALUES (99, ?)', ['Outside both pairs']);
            $operation = $this->openTableMerge(self::ALPHA, self::BETA);
            $asks = $this->mergeFrames();
            $this->deliverMergeFrame($asks[0]);
            $result = Hilos::$db->accountOperationResults->byAssignment($asks[0]->data->data->assignmentId);
            $other = $this->openTableMerge(self::GAMMA, self::TWELVE);
            $otherAssignment = Hilos::$db->accountOperationAssignments->forOperation($other->id)->first();
            $deletion = $this->requestTableDeletion(self::ALPHA);
            $this->asPerson(self::ALPHA, static fn () => Hilos::$db->userMerges->actions->add(self::ALPHA, self::BETA));
            foreach ([
                [HilosDbContext::accountOperations, $operation->id,
                    [EntityAccountOperation::root_user_id => self::GAMMA, EntityAccountOperation::loser_user_id => self::TWELVE],
                    [ObjectAccountOperation::rootUserId => self::GAMMA, ObjectAccountOperation::survivorUserId => self::TWELVE], [1, 2, 3, 12]],
                [HilosDbContext::accountOperationAssignments, $result->assignmentId,
                    [EntityAccountOperationAssignment::operation_id => $other->id],
                    [ObjectAccountOperationAssignment::operationId => $other->id], [1, 2, 3, 12]],
                [HilosDbContext::accountOperationAssignments, $result->assignmentId,
                    [EntityAccountOperationAssignment::user_id => self::GAMMA],
                    [ObjectAccountOperationAssignment::userId => self::GAMMA], [1, 2, 3]],
                [HilosDbContext::accountOperationResults, $result->id,
                    [EntityAccountOperationResult::assignment_id => $otherAssignment->id],
                    [ObjectAccountOperationResult::assignmentId => $otherAssignment->id], [1, 2, 3, 12]],
                [HilosDbContext::accountDeletions, $deletion->id,
                    [EntityAccountDeletion::user_id => self::GAMMA], [ObjectAccountDeletion::userId => self::GAMMA], [1, 3]],
                [HilosDbContext::userMerges, self::ALPHA,
                    [EntityUserMerge::user_id => self::GAMMA, EntityUserMerge::survivor_user_id => self::TWELVE],
                    [ObjectUserMerge::userId => self::GAMMA, ObjectUserMerge::survivorUserId => self::TWELVE], [1, 2, 3, 12]],
            ] as [$source, $id, $entity, $object, $expected]) {
                foreach ([$entity, $object] as $fields) {
                    foreach ([false, true] as $previous) {
                        $change = SourceChange::dbUpdated($source, (string) $id,
                            $previous ? [] : $fields, previous: $previous ? $fields : []);
                        self::assertEqualsCanonicalizing($expected, AccountMergeSourceChange::userIds($change));
                    }
                }
            }
            // Complete tombstone facts resolve without a current row, including the native delete carrier.
            foreach ([
                [EntityUserMerge::user_id => self::GAMMA, EntityUserMerge::survivor_user_id => self::TWELVE],
                [ObjectUserMerge::userId => self::GAMMA, ObjectUserMerge::survivorUserId => self::TWELVE],
            ] as $row) {
                self::assertEqualsCanonicalizing([3, 12], AccountMergeSourceChange::userIds(
                    SourceChange::dbDeleted(HilosDbContext::userMerges, (string) self::GAMMA, $row)));
            }
            foreach ([EntityAccountOperation::circle_user_ids, ObjectAccountOperation::circleUserIds] as $field) {
                foreach ([false, true] as $previous) {
                    $changedCircle = [$field => '[3,12]'];
                    self::assertEqualsCanonicalizing([1, 2, 3, 12, 99], AccountMergeSourceChange::userIds(SourceChange::dbUpdated(
                        HilosDbContext::accountOperations, (string) $operation->id,
                        $previous ? [] : $changedCircle, previous: $previous ? $changedCircle : [])));
                    self::assertEqualsCanonicalizing([1, 2], AccountMergeSourceChange::userIds(SourceChange::dbUpdated(
                        HilosDbContext::accountOperations, (string) $operation->id, [$field => $operation->circleUserIds])));
                }
            }
            foreach ([
                SourceChange::dbDeleted(HilosDbContext::accountOperationAssignments, '9999',
                    [EntityAccountOperationAssignment::operation_id => 9999]),
                SourceChange::dbUpdated(HilosDbContext::accountOperationResults, (string) $result->id, [],
                    previous: [EntityAccountOperationResult::assignment_id => 9999]),
                SourceChange::dbUpdated(HilosDbContext::accountOperationAssignments, (string) $result->assignmentId, [],
                    previous: [EntityAccountOperationAssignment::operation_id => 9999]),
                SourceChange::dbCleared(HilosDbContext::accountOperations),
            ] as $incomplete) {
                self::assertEqualsCanonicalizing([1, 2, 3, 12, 99], AccountMergeSourceChange::userIds($incomplete));
            }
        });
    }

    public function testScheduledDeletionDoesNotHideACandidateButStartAndOpenErasureMakeItUnavailable(): void
    {
        TruthSourceRegistry::unregisterAgent(self::MERGE_OWNER_AGENT_ID);
        $this->withMergeOwners(function (): void {
            $this->seedPeople();
            $deletion = $this->requestTableDeletion(self::BETA);
            self::assertTrue(AccountMergeAvailabilityResolver::of(self::BETA)->available);
            $this->asPerson(self::BETA, static fn () => $deletion->actions->start());
            $mutations = $this->table()->buildMutationsForSourceEvent(SourceChange::dbUpdated(
                HilosDbContext::accountDeletions, (string) $deletion->id, []));
            self::assertSame([self::BETA], array_column($mutations, 'rowKey'));
            self::assertFalse($mutations[0]->row->accountMergeAvailability->available);
            self::assertNotNull(Database::sql('SELECT `started_at` FROM `hilos_account_deletion` WHERE `id` = ?',
                [$deletion->id])->row()['started_at']);
            $operation = $this->asMergeHolder(static fn () => Hilos::$db->accountOperations->actions->recordErasure(
                $deletion->id, self::BETA, AccountOperationCircle::fromArray([self::GAMMA, self::BETA], self::BETA)));
            self::assertFalse(AccountMergeAvailabilityResolver::of(self::GAMMA)->available);
            $mutations = $this->table()->buildMutationsForSourceEvent(SourceChange::dbUpdated(
                HilosDbContext::accountOperations, (string) $operation->id, []));
            self::assertEqualsCanonicalizing([self::BETA, self::GAMMA], array_column($mutations, 'rowKey'));
            foreach ($mutations as $mutation) {
                self::assertSame(TableMutationType::Update, $mutation->type);
                self::assertFalse($mutation->row->accountMergeAvailability->available);
            }
            self::assertSame(3, $this->table()->getPage(new TableQueryDTO(
                filter: [HilosMergeCandidatesTable::FILTER_SURVIVOR => self::ALPHA]))->totalCount);
            self::assertSame(['reserved' => false, 'available' => false], AccountMergeAvailabilityResolver::of(9999)->toArray());
        });
    }

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
        TruthSourceRegistry::register(HilosDbContext::identities, TruthSourceKeys::all(), self::MERGE_OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::secondFactors, TruthSourceKeys::all(), self::MERGE_OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::MERGE_OWNER_AGENT_ID);
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::MERGE_OWNER_AGENT_ID);

        parent::tearDown();
    }

    /** @throws HilosException When a factor or the table cannot be read */
    public function testConfirmedFactorChangesRebuildTheCandidateRow(): void
    {
        $this->seedPeople();
        $factor = Hilos::$db->secondFactors->actions->startEnrolment(self::BETA, 'App', 'JBSWY3DPEHPK3PXP');
        $id = (string)$factor->id;
        $table = $this->table();
        $change = SourceChange::dbCreated(HilosDbContext::secondFactors, $id, [ObjectSecondFactor::userId => self::BETA]);
        self::assertFalse($table->buildMutationForSourceEvent($change)->row->toArray()[HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR]);
        $factor->actions->confirm('App');
        $change = SourceChange::dbUpdated(HilosDbContext::secondFactors, $id, [ObjectSecondFactor::confirmedAt => TimeHelper::nowMs()]);
        self::assertTrue($table->buildMutationForSourceEvent($change)->row->toArray()[HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR]);
        $factor->actions->delete();
        $change = SourceChange::dbDeleted(HilosDbContext::secondFactors, $id, [ObjectSecondFactor::userId => self::BETA]);
        self::assertFalse($table->buildMutationForSourceEvent($change)->row->toArray()[HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR]);
        self::assertArrayNotHasKey(HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR, $table->wireFields());
    }

    /** @throws HilosException When a factor or the candidate row cannot be read */
    public function testFactorSourceOwnerTakesPrecedenceOverTheLiveRow(): void
    {
        $this->seedPeople();
        $factor = Hilos::$db->secondFactors->actions->startEnrolment(self::BETA, 'App', 'JBSWY3DPEHPK3PXP');
        $id = (string) $factor->id;
        $table = $this->table();

        $mutation = $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::secondFactors,
            $id,
            [ObjectSecondFactor::userId => self::GAMMA],
        ));

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame(self::GAMMA, $mutation->rowKey);

        $factor->actions->delete();

        self::assertNull($table->buildMutationForSourceEvent(
            SourceChange::dbDeleted(HilosDbContext::secondFactors, $id),
        ));
    }

    /** @throws HilosException When an identity or the candidate row cannot be read */
    public function testIdentitySourceWithoutAnOwnerUsesTheLiveRowOrIsIgnored(): void
    {
        $this->seedPeople();
        $password = Hilos::$db->identities->findPasswordByUser(self::BETA);
        self::assertNotNull($password);
        $table = $this->table();

        $mutation = $table->buildMutationForSourceEvent(SourceChange::dbUpdated(
            HilosDbContext::identities,
            (string) $password->id,
            [ObjectIdentity::verified => true],
        ));

        self::assertNotNull($mutation);
        self::assertSame(TableMutationType::Update, $mutation->type);
        self::assertSame(self::BETA, $mutation->rowKey);
        self::assertNull($table->buildMutationForSourceEvent(
            SourceChange::dbDeleted(HilosDbContext::identities, '99999'),
        ));
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

    public function testCandidatesCarryNumericActivityWhilePagingByTheirDeclaredIdOrder(): void
    {
        $this->seedPeople();
        Database::sqlRun('UPDATE `hilos_user` SET `last_activity` = ? WHERE `id` IN (?, ?)', [
            1790762400000, self::BETA, self::GAMMA,
        ]);
        $table = $this->table();
        $first = $table->getPage(new TableQueryDTO(limit: 2));
        $this->assertSame([self::ALPHA, self::BETA], self::rowKeys($first->rows));
        $this->assertSame(['id' => self::BETA], $first->lastAnchor?->toArray());
        $this->assertNull($table->browserRow($first->rows[0])[BrowserPageSignalData::sources]['users']['lastActivity']);
        $this->assertSame(
            1790762400000,
            $table->browserRow($first->rows[1])[BrowserPageSignalData::sources]['users']['lastActivity'],
        );
        $next = $table->getPage(new TableQueryDTO(limit: 2, anchor: $first->lastAnchor));
        $this->assertSame([self::GAMMA, self::TWELVE], self::rowKeys($next->rows));
        $this->assertSame(['id' => self::GAMMA], $next->firstAnchor?->toArray());
        $this->assertSame(
            1790762400000,
            $table->browserRow($next->rows[0])[BrowserPageSignalData::sources]['users']['lastActivity'],
        );
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
        Database::sqlRun('UPDATE `hilos_identity` SET `verified` = 0 WHERE `user_id` = ? AND `type` = ?', [self::BETA, self::PASSWORD_TYPE]);
        $table = $this->table();

        $rowBeta = $table->getPage(new TableQueryDTO(search: 'beta@'))->rows[0];
        $sourcesBeta = $table->browserRow($rowBeta)[BrowserPageSignalData::sources];

        $this->assertSame(
            [
                HilosUserTableRow::id => self::BETA,
                HilosUserTableRow::admin => false,
                HilosUserTableRow::block => false,
                HilosUserTableRow::name => 'Beta',
                HilosUserTableRow::lastActivity => null,
            ],
            $sourcesBeta[HilosMergeCandidatesTable::SLOT_USER],
        );
        $this->assertTrue($sourcesBeta[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
        $this->assertSame(
            self::BETA_EMAIL,
            $sourcesBeta[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS],
        );
        $identity = $sourcesBeta[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_IDENTITIES][0];
        $this->assertSame(self::BETA_EMAIL, $identity[ObjectIdentity::identifier]);
        $this->assertArrayNotHasKey('secret', $identity);

        $password = Hilos::$db->identities->findPasswordByUser(self::BETA);
        $this->assertNotNull($password);
        $password->markVerified();

        $rowBetaVerified = $table->getPage(new TableQueryDTO(search: 'beta@'))->rows[0];
        $sourcesBetaVerified = $table->browserRow($rowBetaVerified)[BrowserPageSignalData::sources];
        $this->assertTrue($sourcesBetaVerified[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
        $this->assertNull($sourcesBetaVerified[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);

        $rowAlpha = $table->getPage(new TableQueryDTO(search: 'alpha@'))->rows[0];
        $sourcesAlpha = $table->browserRow($rowAlpha)[BrowserPageSignalData::sources];
        $this->assertFalse($sourcesAlpha[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
        $this->assertNull($sourcesAlpha[HilosMergeCandidatesTable::SLOT_MERGE][HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]);
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
                        HilosMergeCandidatesTable::FIELD_HAS_SECOND_FACTOR => false,
                        HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS => null,
                        HilosUserTableRow::FIELD_ACCOUNT_MERGE_AVAILABILITY => null,
                    ],
                ],
            ],
            $this->table()->browserRow(new HilosMergeCandidateTableRow(
                userFields: $userFields,
                identities: [$identity],
                hasPassword: true,
                hasSecondFactor: false,
            )),
        );
    }

    /**
     * Preserves the unverified password address across candidate row serialization.
     */
    public function testCandidateTableRowPreservesUnverifiedPasswordAddressAcrossSerialization(): void
    {
        $row = new HilosMergeCandidateTableRow(
            userFields: [HilosUserTableRow::id => 7, HilosUserTableRow::name => 'Candidate'],
            identities: [],
            hasPassword: true,
                hasSecondFactor: false,
            exactUserId: 7,
            unverifiedPasswordAddress: 'candidate@example.test',
        );

        $restored = HilosMergeCandidateTableRow::fromArray($row->toArray());

        $this->assertSame('candidate@example.test', $restored->unverifiedPasswordAddress);
        $this->assertSame(7, $restored->getRowKey());
        $this->assertTrue($restored->hasPassword);
        $this->assertSame(7, $restored->exactUserId);
    }

    /**
     * A change of a sign-in method that names only itself is traced to its owner, whose row is refreshed.
     *
     * @throws HilosException On database error
     */
    public function testAnIdentityChangeRefreshesItsOwnersCandidateRow(): void
    {
        $this->seedPeople();
        Database::sqlRun('UPDATE `hilos_identity` SET `verified` = 0 WHERE `user_id` = ? AND `type` = ?', [self::BETA, self::PASSWORD_TYPE]);
        $password = Hilos::$db->identities->findPasswordByUser(self::BETA);
        $this->assertNotNull($password);
        $password->markVerified();

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbUpdated(HilosDbContext::identities, (string) $password->id, [
                ObjectIdentity::userId => self::BETA,
                ObjectIdentity::verified => true,
            ]),
        );

        $this->assertNotNull($mutation);
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame(self::BETA, $mutation->rowKey);
        $this->assertInstanceOf(HilosMergeCandidateTableRow::class, $mutation->row);
        $this->assertNull($mutation->row->unverifiedPasswordAddress);
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
        $mutations = $this->table()->buildMutationsForSourceEvent(SourceChange::dbCreated(
            HilosDbContext::userMerges, (string) self::GAMMA,
            [ObjectUserMerge::userId => self::GAMMA, ObjectUserMerge::survivorUserId => self::ALPHA]));
        self::assertSame([self::GAMMA, self::ALPHA], array_column($mutations, 'rowKey'));
        self::assertSame(TableMutationType::Delete, $mutations[0]->type);
        self::assertSame(TableMutationType::Update, $mutations[1]->type);
    }

    /**
     * A merge row goes only with the folded account itself, whose person row leaves through the people source.
     *
     * @throws HilosException On database error
     */
    public function testRemovingAMergeRowLeavesTheWindowAlone(): void
    {
        $this->seedPeople();
        $mutations = $this->table()->buildMutationsForSourceEvent(SourceChange::dbDeleted(
            HilosDbContext::userMerges, (string) self::GAMMA,
            [ObjectUserMerge::userId => self::GAMMA, ObjectUserMerge::survivorUserId => self::ALPHA]));
        self::assertSame([self::ALPHA], array_column($mutations, 'rowKey'));
        self::assertSame([], $this->table()->buildMutationsForSourceEvent(SourceChange::dbCleared(HilosDbContext::userMerges)));
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

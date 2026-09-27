<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Tables\Legal\AbstractHilosLegalAcceptancesTable;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Legal\DTO\LegalAgreementsStateSignalData;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalTally;
use Hilos\Utils\Helpers\TimeHelper;

/** Acceptance writes use the real users-library entry and database, with its group frames captured. */
final class LegalAcceptanceIntegrationTest extends ProfileIntegrationTestCase
{
    private string $previousAppClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        AcceptanceIntegrationHilos::initBrowser();
        LegalAdminAudience::reset();
    }

    protected function tearDown(): void
    {
        LegalAdminAudience::reset();
        $this->previousAppClass::initBrowser();
        parent::tearDown();
    }

    public function testBothDocumentsAreWrittenAndOneStateFrameNamesThePersonsGroup(): void
    {
        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'old', 'privacy' => 'privacy']);
        self::assertCount(2, Hilos::$db->legalAcceptances->ofUser(self::USER_ID));
        $states = $this->states();
        self::assertCount(1, $states);
        self::assertSame(['terms', 'privacy'], array_column($states[0]->documents, 'document'));
        self::assertSame(['covered', 'covered'], array_column($states[0]->documents, 'standing'));
        self::assertSame('old', $states[0]->documents[0]['held']['revisionId']);
        self::assertSame('wording', $states[0]->documents[0]['current']['revisionId']);
        self::assertIsInt($states[0]->documents[0]['acceptedAt']);
        self::assertSame($states[0]->toArray(), LegalAgreementsStateSignalData::fromArray($states[0]->toArray())->toArray());
    }

    public function testRepeatedAcceptanceRetainsTheFirstTimestampAndRow(): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [self::USER_ID, 'terms', 'old', '2026-01-02 03:04:05'],
        );
        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'old']);
        $first = Hilos::$db->legalAcceptances->findOne(self::USER_ID, LegalDocument::TERMS, 'old');
        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'old']);
        self::assertCount(1, Hilos::$db->legalAcceptances->ofUser(self::USER_ID));
        self::assertSame('2026-01-02 03:04:05', $first?->acceptedAt);
        self::assertSame($first?->id, Hilos::$db->legalAcceptances->findOne(self::USER_ID, LegalDocument::TERMS, 'old')?->id);
        self::assertSame(TimeHelper::sqlToMs('2026-01-02 03:04:05'), $this->states()[0]->documents[0]['acceptedAt']);
    }

    public function testAnUnknownSecondRevisionRefusesTheWholeBatchAndPublishesNothing(): void
    {
        try {
            $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'old', 'privacy' => 'unknown']);
            self::fail('The unknown revision must refuse the batch');
        } catch (UnknownRevisionException) {
            self::assertSame([], Hilos::$db->legalAcceptances->ofUser(self::USER_ID));
            self::assertSame([], $this->states());
        }
        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'old', 'privacy' => 'privacy']);
        self::assertCount(2, Hilos::$db->legalAcceptances->ofUser(self::USER_ID));
    }

    public function testRecordLeavesTheCallersTransactionAndPublicationToTheCaller(): void
    {
        Database::transactionStart();
        try {
            $this->library->legalAcceptanceCommands()->record(self::USER_ID, ['terms' => 'old', 'privacy' => 'privacy']);
            self::assertCount(2, Hilos::$db->legalAcceptances->ofUser(self::USER_ID));
            self::assertSame([], $this->states());
        } finally {
            Database::transactionRollback();
        }
        self::assertSame([], Hilos::$db->legalAcceptances->ofUser(self::USER_ID));
    }

    public function testUndeclaredRowsAreHiddenAndAcceptedHistoryUsesDeclarationOrder(): void
    {
        foreach ([['wording', '2026-02-02'], ['unknown', '2026-02-03'], ['old', '2026-02-04']] as [$id, $date]) {
            Database::sqlRun(
                'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
                [self::USER_ID, 'terms', $id, $date . ' 00:00:00'],
            );
        }
        $state = LegalAgreementsProjector::stateFor(self::USER_ID, '2026-09-27');
        self::assertSame(['old', 'wording'], array_column($state->documents[0]['accepted'], 'revisionId'));
        self::assertSame('wording', $state->documents[0]['held']['revisionId']);
        self::assertSame(TimeHelper::sqlToMs('2026-02-02 00:00:00'), $state->documents[0]['acceptedAt']);
        self::assertSame('none', $state->documents[1]['standing']);
        $texts = LegalAgreementsProjector::textsFor(self::USER_ID, '2026-09-27');
        self::assertNull($texts['documents'][0]['held']);
        self::assertSame([], $texts['documents'][0]['changes']);
        self::assertCount(6, $texts['documents'][0]['current']);
        $history = LegalAgreementsProjector::revisions();
        self::assertSame(['first', 'project'], array_column($history['documents'][0]['revisions'], 'origin'));
        self::assertSame([null, 1], array_column($history['documents'][0]['revisions'], 'previousSetVersion'));
    }

    public function testAnEarlierAcceptanceCarriesItsTextAndAnEmptyWordingDiff(): void
    {
        $this->library->legalAcceptanceCommands()->accept(self::USER_ID, ['terms' => 'old']);
        $texts = LegalAgreementsProjector::textsFor(self::USER_ID, '2026-09-27');
        self::assertSame($texts['documents'][0]['current'], $texts['documents'][0]['held']);
        self::assertSame([], $texts['documents'][0]['changes']);
    }

    public function testAdminReadsIncludeUncachedAndUndeclaredRowsWithoutWriting(): void
    {
        foreach ([
            [self::USER_ID, 'terms', 'wording', '2026-02-01 00:00:00'],
            [self::USER_ID, 'terms', 'old', '2026-02-02 00:00:00'],
            [self::USER_ID, 'terms', 'gone', '2026-02-03 00:00:00'],
            [self::USER_ID, 'retired', 'older', '2026-02-03 00:00:00'],
            [self::OTHER_USER_ID, 'terms', 'gone', '2026-02-03 00:00:00'],
            [self::OTHER_USER_ID, 'privacy', 'privacy', '2026-02-03 00:00:00'],
            [self::ADMIN_USER_ID, 'terms', 'old', '2026-02-03 00:00:00'],
        ] as [$userId, $document, $revision, $acceptedAt]) {
            Database::sqlRun(
                'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
                [$userId, $document, $revision, $acceptedAt],
            );
        }
        self::assertSame(['privacy', 'retired', 'terms'], Hilos::$db->legalAcceptances->documentsOnRecord());
        self::assertSame([
            'privacy' => ['privacy'], 'retired' => ['older'], 'terms' => ['gone', 'old', 'wording'],
        ], Hilos::$db->legalAcceptances->revisionsOnRecord());
        self::assertSame(
            ['old' => 1, 'wording' => 1],
            Hilos::$db->legalAcceptances->heldCounts('terms', ['old', 'wording']),
        );
        self::assertSame(
            ['old' => 2],
            Hilos::$db->legalAcceptances->heldCounts('terms', ['wording', 'old']),
        );
        self::assertSame(
            ['gone' => 2, 'old' => 2, 'wording' => 1],
            Hilos::$db->legalAcceptances->acceptedCounts('terms'),
        );
        self::assertSame(['privacy' => 1], Hilos::$db->legalAcceptances->heldCounts('privacy', ['privacy']));
        self::assertSame([], Hilos::$db->legalAcceptances->heldCounts('terms', []));
        self::assertSame([], Hilos::$db->legalAcceptances->heldCounts('terms', ['not-recorded']));
        self::assertSame([], Hilos::$db->legalAcceptances->acceptedCounts('not-recorded'));
        $tallies = LegalTally::all('2026-09-27');
        self::assertSame(['terms', 'privacy', 'retired'], array_keys($tallies));
        self::assertSame(2, $tallies['terms']->covered);
        self::assertSame(['old' => 1, 'wording' => 1], $tallies['terms']->heldByRevision);
        self::assertSame(['gone' => 2], $tallies['terms']->undeclared);
        self::assertTrue($tallies['privacy']->declared);
        self::assertSame(1, $tallies['privacy']->covered);
        self::assertFalse($tallies['retired']->declared);
        self::assertSame(['older' => 1], $tallies['retired']->undeclared);
        self::assertSame(7, (int) Database::sql('SELECT COUNT(*) AS total FROM hilos_legal_acceptance')->firstRow()['total']);
    }

    public function testAcceptanceWindowsFilterAndPageByTimestampThenNumericId(): void
    {
        foreach ([
            [self::USER_ID, 'terms', 'old', '2026-02-01 00:00:00'],
            [self::OTHER_USER_ID, 'terms', 'old', '2026-02-02 00:00:00'],
            [self::ADMIN_USER_ID, 'terms', 'old', '2026-02-02 00:00:00'],
            [self::USER_ID, 'terms', 'wording', '2026-02-03 00:00:00'],
            [self::USER_ID, 'terms', 'gone', '2026-02-04 00:00:00'],
            [self::USER_ID, 'privacy', 'privacy', '2026-02-03 00:00:00'],
        ] as $values) {
            Database::sqlRun(
                'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
                $values,
            );
        }
        Database::sqlRun(
            "INSERT INTO hilos_identity (user_id, type, identifier, verified) VALUES (?, 'magic_link', ?, 1)",
            [self::OTHER_USER_ID, 'unique-person@example.test'],
        );
        $table = new LegalAcceptanceIntegrationTable();
        $emailMatch = $table->getPage(new TableQueryDTO(search: 'unique-person', limit: 25));
        self::assertSame([self::OTHER_USER_ID], array_column($emailMatch->rows, 'userId'));
        self::assertSame('unique-person@example.test', $emailMatch->rows[0]->email);
        $filter = ['document' => 'terms', 'revision' => 'old'];
        $first = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter, sort: $table->defaultSort()));
        self::assertSame(3, $first->totalCount);
        self::assertSame([self::ADMIN_USER_ID, self::OTHER_USER_ID], array_column($first->rows, 'userId'));
        $second = $table->getPage(new TableQueryDTO(limit: 2, filter: $filter, sort: $table->defaultSort(), anchor: $first->lastAnchor));
        self::assertSame([self::USER_ID], array_column($second->rows, 'userId'));
        self::assertTrue($first->rows[0]->declared);
        self::assertTrue($table->containsRow($first->rows[0]->rowKey, new TableQueryDTO(filter: $filter)));
        $all = $table->getPage(new TableQueryDTO(limit: 25));
        self::assertSame('gone', $all->rows[0]->revisionId);
        self::assertFalse($all->rows[0]->declared);
        self::assertFalse($table->containsRow($all->rows[0]->rowKey, new TableQueryDTO(filter: $filter)));
        self::assertSame(3, $table->getPage(new TableQueryDTO(search: 'Alpha', limit: 25, filter: ['document' => 'terms']))->totalCount);
        self::assertSame(0, $table->getPage(new TableQueryDTO(search: 'Nobody', limit: 25))->totalCount);
        $facets = $table->facetCounts(new TableQueryDTO(filter: $filter), ['revision' => ['old', 'wording', 'gone']]);
        self::assertSame(3, $facets['revision']['options']['old']->count);
        self::assertSame(1, $facets['revision']['options']['wording']->count);
        $mutation = $table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB, HilosDbContext::legalAcceptances, (string) $all->rows[0]->rowKey, TableMutationType::Create,
        ));
        self::assertSame($all->rows[0]->toArray(), $mutation->row->toArray());
        self::assertSame(6, (int) Database::sql('SELECT COUNT(*) AS total FROM hilos_legal_acceptance')->firstRow()['total']);
    }

    public function testBrokenCatalogDoesNotRefuseAcceptanceRowsOrMislabelThem(): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [self::USER_ID, 'terms', 'gone', '2026-02-01 00:00:00'],
        );
        AcceptanceBrokenCatalogHilos::initBrowser();
        $window = new LegalAcceptanceIntegrationTable()->getPage(new TableQueryDTO(limit: 25));
        self::assertCount(1, $window->rows);
        self::assertNull($window->rows[0]->declared);
        self::assertSame([[
            'document' => 'terms', 'declared' => null,
            'revisions' => [['revisionId' => 'gone', 'declared' => null]],
        ]], LegalAdminAudience::filters()->documents);
    }

    public function testFilterVocabularyIncludesRevisionsOutsideTheInitialWindow(): void
    {
        for ($index = 0; $index < 26; $index++) {
            Database::sqlRun(
                'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
                [10000 + $index, 'terms', 'wording', '2026-02-03 00:00:00'],
            );
        }
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [self::USER_ID, 'terms', 'gone', '2026-02-01 00:00:00'],
        );
        $window = new LegalAcceptanceIntegrationTable()->getPage(new TableQueryDTO(limit: 25));
        self::assertCount(25, $window->rows);
        self::assertSame(['wording'], array_values(array_unique(array_column($window->rows, 'revisionId'))));
        self::assertSame([
            ['document' => 'terms', 'declared' => true, 'revisions' => [
                ['revisionId' => 'wording', 'declared' => true],
                ['revisionId' => 'gone', 'declared' => false],
            ]],
            ['document' => 'privacy', 'declared' => true, 'revisions' => []],
        ], LegalAdminAudience::filters()->documents);
    }

    /** @return list<LegalAgreementsStateSignalData> Acceptance states queued for this person */
    private function states(): array
    {
        $states = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_LEGAL_AGREEMENTS_STATE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame('hilos_legal_agreements:' . self::USER_ID, $signal->data->targetGroup);
            self::assertInstanceOf(LegalAgreementsStateSignalData::class, $signal->data->data);
            $states[] = $signal->data->data;
        }

        return $states;
    }
}

/** Two documents with a later terms wording revision. */
final class AcceptanceIntegrationCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'old', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'wording', '2026-02-01', 1, LegalSignificance::EDITORIAL, '2026-02-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}

/** Binds the declared legal documents without changing the profile harness. */
abstract class AcceptanceIntegrationHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AcceptanceIntegrationCatalog::class;
}

/** Project naming boundary for the framework's real SQL acceptance windows. */
final class LegalAcceptanceIntegrationTable extends AbstractHilosLegalAcceptancesTable
{
    /**
     * @param list<int> $userIds People to name
     * @return array<int, string> Fixture person names
     */
    protected function displayNamesOf(array $userIds): array
    {
        return array_fill_keys($userIds, 'Person');
    }

    /**
     * @param string $term Literal name substring
     * @return list<int> Person matching the fixture name
     */
    protected function userIdsNamed(string $term): array
    {
        return str_contains('alpha', mb_strtolower($term)) ? [ProfileIntegrationTestCase::USER_ID] : [];
    }
}

/** Exercises the read-only fallback against a real acceptance table. */
final class AcceptanceBrokenCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array Never returns
     * @throws UnknownRevisionException Always refuses the fixture catalog
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken acceptance catalog');
    }
}

/** Binds the refusing catalog. */
abstract class AcceptanceBrokenCatalogHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AcceptanceBrokenCatalog::class;
}

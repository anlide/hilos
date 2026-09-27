<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Legal\DTO\LegalAgreementsStateSignalData;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
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
    }

    protected function tearDown(): void
    {
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

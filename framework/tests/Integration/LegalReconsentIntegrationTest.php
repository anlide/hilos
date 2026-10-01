<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountDeletion\AccountDeletionSettingsCatalog;
use Hilos\Auth\Library\Command\AuthMessages;
use Hilos\Auth\Library\DTO\LegalAcceptActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewActionDTO;
use Hilos\Auth\Library\DTO\LegalReconsentPreviewReplyDTO;
use Hilos\Auth\Library\DTO\LegalReconsentReplyDTO;
use Hilos\Auth\SecondFactor\SecondFactorSettingsCatalog;
use Hilos\Auth\StepUp\StepUpMessages;
use Hilos\Auth\StepUp\StepUpSettingsCatalog;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Database\Database;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Legal\LegalSignificance;
use Hilos\Users\AccountStandingChangeSubscriber;
use Hilos\Users\AccountStandingResolver;

/**
 * The "the terms have changed" screen against the real tables: what it shows, the acceptance that
 * lifts a freeze, and the administrator's preview (HIL-500).
 *
 * The catalog carries two substantial revisions of each document: the second terms have been in
 * force since March, the second privacy policy takes effect in 2999. A person who accepted only the
 * first of each is past the terms' deadline and inside the privacy policy's window.
 */
final class LegalReconsentIntegrationTest extends ProfileIntegrationTestCase
{
    /** The deadline the second revision of the terms set, long passed. */
    private const string TERMS_DEADLINE = '2026-03-01';

    /** The deadline the second revision of the privacy policy sets, far ahead. */
    private const string PRIVACY_DEADLINE = '2999-01-01';

    private string $previousAppClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppClass = Hilos::appClass();
        ReconsentIntegrationHilos::initBrowser();
        Hilos::$setting = new SettingsAccessor(ReconsentIntegrationSettingsCatalog::class);
        SourceChangeBus::subscribe(new AccountStandingChangeSubscriber());
        AccountStandingResolver::forgetAll();
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`, `admin`) VALUES (?, 'Person', 0), (?, 'Other', 0)",
            [self::USER_ID, self::OTHER_USER_ID],
        );
    }

    protected function tearDown(): void
    {
        AccountStandingResolver::forgetAll();
        $this->previousAppClass::initBrowser();
        parent::tearDown();
    }

    public function testTheScreenShowsTheLapsedAndTheWindowDocumentsInDeclarationOrder(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-1');
        self::accept(self::USER_ID, 'privacy', 'privacy-1');

        $reply = $this->library->onAgentAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_RECONSENT, new LegalReconsentActionDTO());

        self::assertInstanceOf(LegalReconsentReplyDTO::class, $reply);
        self::assertSame(LegalSettings::REFUSAL_FREEZE, $reply->refusal);
        self::assertSame(
            [
                ['terms', 'lapsed', self::TERMS_DEADLINE, 'terms-1', 'terms-2'],
                ['privacy', 'window', self::PRIVACY_DEADLINE, 'privacy-1', 'privacy-2'],
            ],
            array_map(
                static fn (array $document): array => [
                    $document['document'],
                    $document['standing'],
                    $document['deadline'],
                    $document['held']['revisionId'],
                    $document['current']['revisionId'],
                ],
                $reply->documents,
            ),
        );
        self::assertSame([], $reply->documents[0]['changes']);
        self::assertNotSame([], $reply->documents[0]['clauses']);
    }

    public function testNothingIsShownForACoveredDocumentOrOneNeverAccepted(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-2');

        $reply = $this->library->onAgentAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_RECONSENT, new LegalReconsentActionDTO());

        self::assertInstanceOf(LegalReconsentReplyDTO::class, $reply);
        self::assertSame([], $reply->documents);
    }

    public function testTheScreenNamesTheRemindSetting(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-1');
        ReconsentIntegrationSettings::$refusal = LegalSettings::REFUSAL_REMIND;
        Hilos::$setting = new ReconsentIntegrationSettings(ReconsentIntegrationSettingsCatalog::class);
        try {
            $reply = $this->library->onAgentAction(self::ACCEPT_KEY, HilosSignalConstants::HILOS_LEGAL_RECONSENT, new LegalReconsentActionDTO());
        } finally {
            ReconsentIntegrationSettings::$refusal = LegalSettings::REFUSAL_FREEZE;
        }

        self::assertInstanceOf(LegalReconsentReplyDTO::class, $reply);
        self::assertSame(LegalSettings::REFUSAL_REMIND, $reply->refusal);
        self::assertCount(1, $reply->documents);
    }

    public function testAcceptingTheRevisionsInForceLiftsTheFreeze(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-1');
        self::accept(self::USER_ID, 'privacy', 'privacy-1');
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));
        $this->drain();

        $this->submit(
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
            new LegalAcceptActionDTO(['terms' => 'terms-2', 'privacy' => 'privacy-2']),
        );

        self::assertSame(
            ['privacy' => ['privacy-1', 'privacy-2'], 'terms' => ['terms-1', 'terms-2']],
            self::acceptedBy(self::USER_ID),
        );
        $standing = AccountStandingResolver::of(self::USER_ID);
        self::assertFalse($standing->frozen);
        self::assertSame([], $standing->lapsed);
        self::assertSame([], $standing->window);
        self::assertContains(HilosSignalConstants::HILOS_LEGAL_AGREEMENTS_STATE, $this->drain());
    }

    public function testAcceptingAgainWritesNoSecondRecord(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-1');
        $accept = new LegalAcceptActionDTO(['terms' => 'terms-2']);

        $this->submit(HilosSignalConstants::HILOS_LEGAL_ACCEPT, $accept);
        $this->submit(HilosSignalConstants::HILOS_LEGAL_ACCEPT, $accept);

        self::assertSame(['terms' => ['terms-1', 'terms-2']], self::acceptedBy(self::USER_ID));
    }

    public function testARevisionNoLongerInForceIsRefusedWithoutARecord(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-1');

        $this->assertRefused(
            AuthMessages::CONSENT_REVISED,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
            new LegalAcceptActionDTO(['terms' => 'terms-1', 'privacy' => 'privacy-2']),
        );

        self::assertSame(['terms' => ['terms-1']], self::acceptedBy(self::USER_ID));
    }

    public function testAnEmptySetIsRefused(): void
    {
        $this->assertRefused(
            'Name at least one document to accept',
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
            new LegalAcceptActionDTO([]),
        );

        self::assertSame([], self::acceptedBy(self::USER_ID));
    }

    public function testAnUndeclaredDocumentIsRefused(): void
    {
        $this->assertRefused(
            'Legal document cookies is not declared in this installation',
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
            new LegalAcceptActionDTO(['cookies' => 'cookies-1']),
        );

        self::assertSame([], self::acceptedBy(self::USER_ID));
    }

    public function testNobodyAcceptsOnSomebodyElsesBehalf(): void
    {
        self::accept(self::USER_ID, 'terms', 'terms-1');
        Database::sqlRun(
            'UPDATE `hilos_session` SET `impersonator_user_id` = ? WHERE `token` = ?',
            [self::OTHER_USER_ID, self::SESSION_TOKEN],
        );

        $this->assertRefused(
            StepUpMessages::IMPERSONATED,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT,
            new LegalAcceptActionDTO(['terms' => 'terms-2']),
        );

        self::assertSame(['terms' => ['terms-1']], self::acceptedBy(self::USER_ID));
        self::assertTrue(AccountStandingResolver::isFrozen(self::USER_ID));
    }

    public function testThePreviewShowsALapsedAndAWindowRevisionThroughThePreviousHoldersEyes(): void
    {
        $terms = $this->preview('terms');
        $privacy = $this->preview('privacy');

        self::assertSame(['lapsed', self::TERMS_DEADLINE, 'terms-1', 'terms-2'], [
            $terms->standing,
            $terms->deadline,
            $terms->held['revisionId'] ?? null,
            $terms->current['revisionId'],
        ]);
        self::assertSame(['window', self::PRIVACY_DEADLINE, 'privacy-1', 'privacy-2'], [
            $privacy->standing,
            $privacy->deadline,
            $privacy->held['revisionId'] ?? null,
            $privacy->current['revisionId'],
        ]);
        self::assertNotSame([], $terms->clauses);
    }

    public function testThePreviewOfAFirstRevisionHasNothingToCompareAndAnEditorialOneAsksNobody(): void
    {
        ReconsentFirstRevisionHilos::initBrowser();

        $terms = $this->preview('terms');
        $privacy = $this->preview('privacy');

        self::assertNull($terms->standing);
        self::assertNull($terms->deadline);
        self::assertNull($terms->held);
        self::assertSame([], $terms->changes);
        self::assertSame('terms-1', $terms->current['revisionId']);
        self::assertNotSame([], $terms->clauses);
        self::assertSame('covered', $privacy->standing);
        self::assertNull($privacy->deadline);
        self::assertSame('privacy-1', $privacy->held['revisionId'] ?? null);
        self::assertSame('privacy-2', $privacy->current['revisionId']);
    }

    public function testThePreviewRefusesAnUnknownDocument(): void
    {
        $this->expectException(HilosException::class);

        $this->library->onAgentAction(
            self::ANONYMOUS_ACCEPT_KEY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW,
            new LegalReconsentPreviewActionDTO('cookies'),
        );
    }

    /**
     * Asks the preview of one document the way the admin page does - from any tab, signed in or not.
     *
     * @param string $document Document previewed
     * @return LegalReconsentPreviewReplyDTO The reply
     * @throws HilosException When the preview is refused
     */
    private function preview(string $document): LegalReconsentPreviewReplyDTO
    {
        $reply = $this->library->onAgentAction(
            self::ANONYMOUS_ACCEPT_KEY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW,
            new LegalReconsentPreviewActionDTO($document),
        );
        self::assertInstanceOf(LegalReconsentPreviewReplyDTO::class, $reply);

        return $reply;
    }

    /**
     * Drains the queue and names the signals it held.
     *
     * @return list<string> Signal names in queue order
     */
    private function drain(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->data instanceof WebSocketSignalData) {
                $names[] = $signal->signalName->getName();
            }
        }

        return $names;
    }

    /**
     * @param int $userId Person whose records to read
     * @return array<string, list<string>> Accepted revisions by document, both sorted
     * @throws HilosException When the records cannot be read
     */
    private static function acceptedBy(int $userId): array
    {
        $accepted = [];
        foreach (Hilos::$db->legalAcceptances->ofUser($userId) as $acceptance) {
            $accepted[$acceptance->document][] = $acceptance->revisionId;
        }
        ksort($accepted);
        foreach ($accepted as &$revisionIds) {
            sort($revisionIds);
        }

        return $accepted;
    }

    /**
     * @param int $userId Person accepting
     * @param string $document Document key
     * @param string $revisionId Revision accepted
     * @throws HilosException When the insert fails
     */
    private static function accept(int $userId, string $document, string $revisionId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [$userId, $document, $revisionId, '2026-01-02 00:00:00'],
        );
    }
}

/** Terms whose second revision has been in force since March; privacy whose second takes effect in 2999. */
final class ReconsentIntegrationCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'terms-1', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'terms-2', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy-1', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::PRIVACY, 'privacy-2', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2999-01-01', []),
            ],
        ];
    }
}

/** Terms in their first revision; privacy whose second revision is editorial. */
final class ReconsentFirstRevisionCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'terms-1', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'privacy-1', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::PRIVACY, 'privacy-2', '2026-02-01', 1, LegalSignificance::EDITORIAL, '2026-02-01', []),
            ],
        ];
    }
}

/** Binds the catalog with a lapsed and a window revision. */
abstract class ReconsentIntegrationHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = ReconsentIntegrationCatalog::class;
}

/** Binds the catalog with a first and an editorial revision. */
abstract class ReconsentFirstRevisionHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = ReconsentFirstRevisionCatalog::class;
}

/** The profile's settings with the legal ones. */
final class ReconsentIntegrationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        return array_replace(
            StepUpSettingsCatalog::getCatalog(),
            SecondFactorSettingsCatalog::getCatalog(),
            AccountDeletionSettingsCatalog::getCatalog(),
            LegalSettingsCatalog::getCatalog(),
        );
    }
}

/** Settings whose refusal treatment a case switches at the persistence seam. */
final class ReconsentIntegrationSettings extends SettingsAccessor
{
    /** Treatment of a refusal after the deadline the case has set. */
    public static string $refusal = LegalSettings::REFUSAL_FREEZE;

    /**
     * @param string $key Setting key
     * @return mixed The scripted refusal treatment, or the stored value of any other key
     * @throws HilosException When another key cannot be read
     */
    public function effectiveValueFor(string $key): mixed
    {
        return $key === LegalSettings::REFUSAL_KEY ? self::$refusal : parent::effectiveValueFor($key);
    }
}

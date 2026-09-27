<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Legal;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Hilos;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalRevision;
use Hilos\Pages\Legal\AbstractHilosLegalAcceptancesPage;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentPage;
use Hilos\Pages\Legal\AbstractHilosLegalPage;
use Hilos\Pages\Legal\AbstractHilosLegalRevisionPage;
use Hilos\Pages\Legal\AbstractHilosLegalSettingsPage;
use Hilos\Pages\Legal\DTO\HilosLegalSettingSetActionDTO;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use ReflectionClass;
use ReflectionMethod;

/** First-response contents, catalog refusal and validation at the read-only admin boundary. */
final class LegalAdminPagesTest extends LegalAdminTestCase
{
    public function testEveryLegalPageRequiresAdmin(): void
    {
        foreach ([
            AbstractHilosLegalPage::class,
            AbstractHilosLegalDocumentPage::class,
            AbstractHilosLegalRevisionPage::class,
            AbstractHilosLegalAcceptancesPage::class,
            AbstractHilosLegalSettingsPage::class,
        ] as $page) {
            self::assertSame(PageAccessLevel::ADMIN, $page::ACCESS_LEVEL);
        }
    }

    public function testDocumentResponseContainsTheSetAndTheCurrentRevision(): void
    {
        $payload = $this->payload(LegalDocumentPageFixture::class, ['documentKey' => 'terms']);
        self::assertNull($payload->data['legalCatalogRefusal']);
        $document = $payload->data['legalDocument'];
        self::assertTrue($document['declared']);
        self::assertSame('current', $document['revision']['revisionId']);
        self::assertSame(1, $document['set']['version']);
        self::assertCount(6, $document['set']['clauses']);
        self::assertSame([], $document['deviations']);
        self::assertNull($document['newerSet']);
    }

    public function testRevisionResponseCarriesTextAndComparisonWithoutAFollowupAction(): void
    {
        $payload = $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'current']);
        $revision = $payload->data['legalRevision'];
        self::assertTrue($revision['declared']);
        self::assertTrue($revision['current']);
        self::assertSame('first', $revision['predecessorId']);
        self::assertCount(6, $revision['clauses']);
        self::assertSame([], $revision['changes']);
        $first = $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'first']);
        self::assertNull($first->data['legalRevision']['changes']);
        self::assertNull($first->data['legalRevision']['predecessorId']);
    }

    public function testAnUndeclaredRevisionWithRecordsHasNoInventedText(): void
    {
        $payload = $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'gone']);
        self::assertFalse($payload->data['legalRevision']['declared']);
        self::assertNull($payload->data['legalRevision']['clauses']);
        self::assertNull($payload->data['legalRevision']['revision']);
    }

    public function testAnUnknownDocumentIsRefused(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal document');
        $this->payload(LegalDocumentPageFixture::class, ['documentKey' => 'unknown']);
    }

    public function testARevisionWithNeitherDeclarationNorRecordsIsRefused(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal revision');
        $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'unknown']);
    }

    public function testCatalogFailureReachesAllThreePagesAndEmptiesTheirTables(): void
    {
        LegalBrokenCatalogHilos::initBrowser();
        foreach ([LegalRootPageFixture::class, LegalDocumentPageFixture::class, LegalRevisionPageFixture::class] as $page) {
            $payload = $this->payload($page, ['documentKey' => 'terms', 'revisionId' => 'first']);
            self::assertSame('Broken catalog fixture', $payload->data['legalCatalogRefusal']);
        }
        foreach ([new HilosLegalDocumentsTable(), new HilosLegalChecksTable(), new HilosLegalRevisionsTable()] as $table) {
            self::assertSame(0, $table->getFullSnapshot()->totalCount);
        }
    }

    public function testTheSettingActionRoundtripKeepsItsKeyAndValue(): void
    {
        $dto = new HilosLegalSettingSetActionDTO('legal.consent_form', 'line');
        self::assertSame(HilosSignalConstants::LEGAL_SETTING_SET, $dto->getAction());
        self::assertSame($dto->toArray(), HilosLegalSettingSetActionDTO::fromArray($dto->toArray())->toArray());
    }

    /**
     * @param class-string<AbstractHilosLegalPage> $pageClass Page fixture
     * @param array<string, string> $params Route keys
     * @return PagePayload First-response data
     */
    private function payload(string $pageClass, array $params): PagePayload
    {
        return new ReflectionMethod($pageClass, 'buildPagePayload')->invoke(
            new ReflectionClass($pageClass)->newInstanceWithoutConstructor(),
            'viewer',
            new PageRouteParams($params),
        );
    }
}

/** Concrete root for projection calls only. */
final class LegalRootPageFixture extends AbstractHilosLegalPage
{
}

/** Concrete document for projection calls only. */
final class LegalDocumentPageFixture extends AbstractHilosLegalDocumentPage
{
}

/** Concrete revision for projection calls only. */
final class LegalRevisionPageFixture extends AbstractHilosLegalRevisionPage
{
}

/** Deliberate catalog refusal; structural catalog checks have their own suite. */
final class LegalBrokenCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Never returns
     * @throws UnknownRevisionException Always, to exercise the page refusal boundary
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken catalog fixture');
    }
}

/** Binds the faulty declaration provider. */
abstract class LegalBrokenCatalogHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalBrokenCatalog::class;
}

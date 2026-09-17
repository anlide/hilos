<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\Exception\DocumentWithoutRevisionsException;
use Hilos\Legal\Exception\DuplicateDeviationException;
use Hilos\Legal\Exception\DuplicateRevisionIdException;
use Hilos\Legal\Exception\LegalTextFileMissingException;
use Hilos\Legal\Exception\MisplacedRevisionException;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\Exception\UnknownStandardClauseException;
use Hilos\Legal\Exception\UnknownStandardSetVersionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the faults a legal catalog declaration is refused for.
 *
 * Each fault stands on its own deliberately broken fixture, bound through a facade the way a
 * running process binds its project. Every fault surfaces on the first read of ANY document -
 * `documents()` here - because the whole catalog is validated at once, before anything composes.
 */
final class LegalCatalogValidationTest extends TestCase
{
    public const string REVISION_ID = '2026-01-10';

    public const string SECOND_REVISION_ID = '2026-02-20';

    /**
     * Restores the base facade so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testARevisionOnASetVersionTheFrameworkLacksIsRefused(): void
    {
        UnknownSetVersionHilos::initBrowser();

        $this->expectException(UnknownStandardSetVersionException::class);

        LegalCatalogResolver::documents();
    }

    /**
     * The clause is judged against the adopting revision's own document and set version: a
     * privacy clause is no target for a terms deviation.
     */
    public function testADeviationFromAClauseAbsentFromItsSetIsRefused(): void
    {
        UnknownClauseHilos::initBrowser();

        $this->expectException(UnknownStandardClauseException::class);

        LegalCatalogResolver::documents();
    }

    public function testTwoRevisionsOfOneDocumentUnderOneIdAreRefused(): void
    {
        DuplicateRevisionIdHilos::initBrowser();

        $this->expectException(DuplicateRevisionIdException::class);

        LegalCatalogResolver::documents();
    }

    /**
     * A document with nothing to accept is omitted, not declared empty: the empty list claims a
     * document exists and gives nobody its text.
     */
    public function testADocumentDeclaredWithNoRevisionsIsRefused(): void
    {
        EmptyDocumentHilos::initBrowser();

        $this->expectException(DocumentWithoutRevisionsException::class);

        LegalCatalogResolver::documents();
    }

    public function testAMissingDeviationTextFileIsRefused(): void
    {
        MissingTextFileHilos::initBrowser();

        $this->expectException(LegalTextFileMissingException::class);

        LegalCatalogResolver::documents();
    }

    public function testAnEmptyTextFileIsRefused(): void
    {
        $this->expectException(LegalTextFileMissingException::class);

        LegalCatalogResolver::text(__DIR__ . '/Fixtures/empty.txt');
    }

    public function testReadingARevisionNobodyDeclaredIsRefused(): void
    {
        SoundCatalogHilos::initBrowser();

        self::assertSame([LegalDocument::TERMS], LegalCatalogResolver::documents());

        $this->expectException(UnknownRevisionException::class);

        LegalCatalogResolver::compose(LegalDocument::TERMS, '1999-01-01');
    }

    /**
     * A copied block with one field left unchanged: the revision says privacy, the key says terms.
     * Refused rather than regrouped, since either reading may be the wrong one.
     */
    public function testARevisionUnderAnotherDocumentsKeyIsRefused(): void
    {
        MisplacedRevisionHilos::initBrowser();

        $this->expectException(MisplacedRevisionException::class);

        LegalCatalogResolver::documents();
    }

    public function testTwoDeviationsOverOneClauseAreRefused(): void
    {
        DuplicateDeviationHilos::initBrowser();

        $this->expectException(DuplicateDeviationException::class);

        LegalCatalogResolver::documents();
    }

    public function testAnUnknownSetVersionIsRefusedOnADirectRead(): void
    {
        $this->expectException(UnknownStandardSetVersionException::class);

        StandardSetCatalog::set(LegalDocument::PRIVACY, 99);
    }

    /**
     * The significance rule, lowering side: a revision moving to a substantial set version may
     * not call itself editorial.
     *
     * Judged on the predicate the resolver throws `SignificanceLoweredException` on, because the
     * framework ships set version 1 only and a revision cannot yet move to a higher one; the
     * throw becomes reachable through a catalog the day a document publishes set version 2.
     */
    public function testAdoptingASubstantialSetAsEditorialLowersIt(): void
    {
        $predecessor = self::revisionOn(1, LegalSignificance::SUBSTANTIAL);
        $revision = self::revisionOn(2, LegalSignificance::EDITORIAL);

        self::assertTrue($revision->lowersAdoptedSetSignificance($predecessor, self::setVersion(2, LegalSignificance::SUBSTANTIAL)));
    }

    /**
     * The significance rule, raising side: the project may declare more than the framework did,
     * or the same; and a revision that stays on its predecessor's set version is not judged.
     */
    public function testRaisingOrKeepingTheSetSignificanceIsAllowed(): void
    {
        $predecessor = self::revisionOn(1, LegalSignificance::EDITORIAL);

        self::assertFalse(
            self::revisionOn(2, LegalSignificance::SUBSTANTIAL)
                ->lowersAdoptedSetSignificance($predecessor, self::setVersion(2, LegalSignificance::EDITORIAL)),
            'raised above an editorial set',
        );
        self::assertFalse(
            self::revisionOn(2, LegalSignificance::SUBSTANTIAL)
                ->lowersAdoptedSetSignificance($predecessor, self::setVersion(2, LegalSignificance::SUBSTANTIAL)),
            'kept at a substantial set',
        );
        self::assertFalse(
            self::revisionOn(1, LegalSignificance::EDITORIAL)
                ->lowersAdoptedSetSignificance($predecessor, self::setVersion(1, LegalSignificance::SUBSTANTIAL)),
            'no newly adopted set',
        );
    }

    /**
     * @param int $setVersion Set version adopted
     * @param LegalSignificance $significance Significance declared
     * @return LegalRevision Terms revision without deviations
     */
    private static function revisionOn(int $setVersion, LegalSignificance $significance): LegalRevision
    {
        return new LegalRevision(LegalDocument::TERMS, self::REVISION_ID, self::REVISION_ID, $setVersion, $significance, self::REVISION_ID, []);
    }

    /**
     * @param int $version Set version
     * @param LegalSignificance $significance Significance the framework declares on it
     * @return StandardSet Terms set without clauses
     */
    private static function setVersion(int $version, LegalSignificance $significance): StandardSet
    {
        return new StandardSet(LegalDocument::TERMS, $version, self::REVISION_ID, $significance, []);
    }
}

/**
 * Builds the revisions and deviations the broken fixtures below share.
 */
final class LegalCatalogValidationFixtures
{
    /**
     * @param string $clauseKey Clause the deviation names
     * @param string $textFile Absolute path of its text file
     * @return Deviation Stricter deviation
     */
    public static function deviation(
        string $clauseKey,
        string $textFile = __DIR__ . '/Fixtures/terms/standard.retention.2026-02-20.txt',
    ): Deviation {
        return new Deviation($clauseKey, DeviationDirection::STRICTER, 'Differs from the standard', $textFile);
    }

    /**
     * @param string $id Revision id
     * @param list<Deviation> $deviations Deviations of the revision
     * @param int $setVersion Set version adopted
     * @param LegalDocument $document Document the revision names
     * @return LegalRevision Substantial revision
     */
    public static function revision(
        string $id = LegalCatalogValidationTest::REVISION_ID,
        array $deviations = [],
        int $setVersion = 1,
        LegalDocument $document = LegalDocument::TERMS,
    ): LegalRevision {
        return new LegalRevision($document, $id, $id, $setVersion, LegalSignificance::SUBSTANTIAL, $id, $deviations);
    }
}

/** Adopts a set version the framework never published. */
final class UnknownSetVersionCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [LegalDocument::TERMS->value => [LegalCatalogValidationFixtures::revision(setVersion: 99)]];
    }
}

/** Deviates on terms from a clause only the privacy set carries. */
final class UnknownClauseCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                LegalCatalogValidationFixtures::revision(
                    deviations: [LegalCatalogValidationFixtures::deviation(StandardSetCatalog::CLAUSE_NO_SALE)],
                ),
            ],
        ];
    }
}

/** Declares two terms revisions under one id. */
final class DuplicateRevisionIdCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                LegalCatalogValidationFixtures::revision(),
                LegalCatalogValidationFixtures::revision(),
            ],
        ];
    }
}

/** Declares a sound terms document beside an empty privacy one. */
final class EmptyDocumentCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [LegalCatalogValidationFixtures::revision()],
            LegalDocument::PRIVACY->value => [],
        ];
    }
}

/** Names a deviation text file nobody shipped. */
final class MissingTextFileCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                LegalCatalogValidationFixtures::revision(
                    deviations: [
                        LegalCatalogValidationFixtures::deviation(
                            StandardSetCatalog::CLAUSE_RETENTION,
                            __DIR__ . '/Fixtures/terms/standard.retention.1999-01-01.txt',
                        ),
                    ],
                ),
            ],
        ];
    }
}

/** Declares two sound terms revisions, the first plain and the second deviating once. */
final class SoundCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                LegalCatalogValidationFixtures::revision(),
                LegalCatalogValidationFixtures::revision(
                    LegalCatalogValidationTest::SECOND_REVISION_ID,
                    [LegalCatalogValidationFixtures::deviation(StandardSetCatalog::CLAUSE_RETENTION)],
                ),
            ],
        ];
    }
}

/** Declares a privacy revision under the terms key. */
final class MisplacedRevisionCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [LegalDocument::TERMS->value => [LegalCatalogValidationFixtures::revision(document: LegalDocument::PRIVACY)]];
    }
}

/** Deviates twice from one terms clause within one revision. */
final class DuplicateDeviationCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                LegalCatalogValidationFixtures::revision(
                    deviations: [
                        LegalCatalogValidationFixtures::deviation(StandardSetCatalog::CLAUSE_RETENTION),
                        LegalCatalogValidationFixtures::deviation(StandardSetCatalog::CLAUSE_RETENTION),
                    ],
                ),
            ],
        ];
    }
}

/** Facade fixture binding {@see UnknownSetVersionCatalog}. */
abstract class UnknownSetVersionHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = UnknownSetVersionCatalog::class;
}

/** Facade fixture binding {@see UnknownClauseCatalog}. */
abstract class UnknownClauseHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = UnknownClauseCatalog::class;
}

/** Facade fixture binding {@see DuplicateRevisionIdCatalog}. */
abstract class DuplicateRevisionIdHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = DuplicateRevisionIdCatalog::class;
}

/** Facade fixture binding {@see EmptyDocumentCatalog}. */
abstract class EmptyDocumentHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = EmptyDocumentCatalog::class;
}

/** Facade fixture binding {@see MissingTextFileCatalog}. */
abstract class MissingTextFileHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = MissingTextFileCatalog::class;
}

/** Facade fixture binding {@see SoundCatalog}. */
abstract class SoundCatalogHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = SoundCatalog::class;
}

/** Facade fixture binding {@see MisplacedRevisionCatalog}. */
abstract class MisplacedRevisionHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = MisplacedRevisionCatalog::class;
}

/** Facade fixture binding {@see DuplicateDeviationCatalog}. */
abstract class DuplicateDeviationHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = DuplicateDeviationCatalog::class;
}

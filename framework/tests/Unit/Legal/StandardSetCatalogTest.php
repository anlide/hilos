<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\ComposedClause;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\StandardClause;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework's own standard sets: what ships with them, and that nothing shipped
 * is ever taken away.
 *
 * A revision holds a set version for as long as a person holds the revision, so a set version
 * removed or renumbered here breaks documents the framework cannot see. These tests make that a
 * red build rather than a surprise after the deploy.
 */
final class StandardSetCatalogTest extends TestCase
{
    /**
     * Restores the base facade so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    /**
     * Every published version with its clause keys in order. Publishing a set version appends an
     * entry here; nothing already listed may change.
     */
    public function testNoPublishedSetVersionIsEverTakenAway(): void
    {
        self::assertSame(
            [
                LegalDocument::TERMS->value => [
                    1 => [
                        StandardSetCatalog::CLAUSE_FILE_ACCESS,
                        StandardSetCatalog::CLAUSE_RETENTION,
                        StandardSetCatalog::CLAUSE_MODERATION,
                        StandardSetCatalog::CLAUSE_AVAILABILITY,
                        StandardSetCatalog::CLAUSE_ACCOUNT_RULES,
                        StandardSetCatalog::CLAUSE_OWNERSHIP,
                    ],
                ],
                LegalDocument::PRIVACY->value => [
                    1 => [
                        StandardSetCatalog::CLAUSE_NO_SALE,
                        StandardSetCatalog::CLAUSE_DELETION,
                        StandardSetCatalog::CLAUSE_EXPORT,
                        StandardSetCatalog::CLAUSE_PASSWORDS,
                        StandardSetCatalog::CLAUSE_ACCESS_LOG,
                        StandardSetCatalog::CLAUSE_SESSION_DATA,
                        StandardSetCatalog::CLAUSE_BREACH_NOTICE,
                    ],
                ],
            ],
            self::publishedKeys(),
        );
    }

    public function testEveryDeclaredTextFileHoldsText(): void
    {
        foreach (LegalDocument::cases() as $document) {
            foreach (StandardSetCatalog::sets($document) as $set) {
                foreach ($set->clauses as $clause) {
                    self::assertNotSame('', LegalCatalogResolver::text($clause->textFile), $clause->textFile);
                    self::assertSame(
                        "{$clause->key}.txt",
                        preg_replace('/\.\d{4}-\d{2}-\d{2}\.txt$/', '.txt', basename($clause->textFile)),
                        'a text file is named by its clause key and the date its text was written',
                    );
                }
            }
        }
    }

    public function testClauseKeysAreUniqueWithinASetVersion(): void
    {
        foreach (LegalDocument::cases() as $document) {
            foreach (StandardSetCatalog::sets($document) as $set) {
                $keys = array_map(static fn (StandardClause $clause): string => $clause->key, $set->clauses);
                self::assertSame(array_values(array_unique($keys)), $keys, "{$document->value} set {$set->version}");
            }
        }
    }

    public function testVersionsAscendFromOneWithoutGaps(): void
    {
        foreach (LegalDocument::cases() as $document) {
            $sets = StandardSetCatalog::sets($document);

            self::assertSame(
                range(1, count($sets)),
                array_map(static fn (StandardSet $set): int => $set->version, $sets),
                $document->value,
            );
            foreach ($sets as $set) {
                self::assertSame($document, $set->document);
            }
            self::assertSame($sets[array_key_last($sets)], StandardSetCatalog::latest($document));
        }
    }

    /**
     * Composed through a catalog declaring one plain revision per published set version, the way
     * a project standing on an old version still composes after newer ones ship.
     */
    public function testEveryPublishedVersionStillComposes(): void
    {
        EverySetVersionHilos::initBrowser();

        foreach (LegalDocument::cases() as $document) {
            foreach (LegalCatalogResolver::revisions($document) as $revision) {
                $composed = LegalCatalogResolver::compose($document, $revision->id);

                self::assertSame(
                    array_map(static fn (StandardClause $clause): string => $clause->key, $composed->set->clauses),
                    array_map(static fn (ComposedClause $clause): string => $clause->standard->key, $composed->clauses),
                );
            }
        }
    }

    /**
     * @return array<string, array<int, list<string>>> Clause keys per set version per document value
     */
    private static function publishedKeys(): array
    {
        $published = [];
        foreach (LegalDocument::cases() as $document) {
            foreach (StandardSetCatalog::sets($document) as $set) {
                $published[$document->value][$set->version] = array_map(
                    static fn (StandardClause $clause): string => $clause->key,
                    $set->clauses,
                );
            }
        }

        return $published;
    }
}

/**
 * Declares, for each document, one plain revision on every published set version in turn.
 */
final class EverySetVersionCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        $revisions = [];
        foreach (LegalDocument::cases() as $document) {
            foreach (StandardSetCatalog::sets($document) as $set) {
                $id = "set-{$set->version}";
                $revisions[$document->value][] = new LegalRevision(
                    $document,
                    $id,
                    $set->publishedOn,
                    $set->version,
                    $set->significance,
                    $set->publishedOn,
                    [],
                );
            }
        }

        return $revisions;
    }
}

/**
 * Facade fixture binding {@see EverySetVersionCatalog}.
 */
abstract class EverySetVersionHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = EverySetVersionCatalog::class;
}

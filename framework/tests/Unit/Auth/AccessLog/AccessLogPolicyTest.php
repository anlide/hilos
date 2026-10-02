<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\AccessLog;

use Hilos\Auth\AccessLog\AccessLogPolicy;
use Hilos\Hilos;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for reading the access log switch out of the project's privacy text (HIL-1174).
 *
 * Each catalog is bound the way a running process binds it - a facade fixture naming its own
 * provider, captured by initBrowser() - and each fixture is a provider of its own, because the
 * policy keeps its answers per provider for the life of the process.
 */
final class AccessLogPolicyTest extends TestCase
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

    public function testWithoutACatalogBothAreKeptAsTheStandardSays(): void
    {
        Hilos::initBrowser();

        self::assertTrue(AccessLogPolicy::keepsLog());
        self::assertTrue(AccessLogPolicy::keepsSessionAddress());
    }

    public function testACatalogWithoutAPrivacyDocumentKeepsBoth(): void
    {
        AccessLogPolicyTermsOnlyHilos::initBrowser();

        self::assertTrue(AccessLogPolicy::keepsLog());
        self::assertTrue(AccessLogPolicy::keepsSessionAddress());
    }

    public function testAStandardPrivacyTextKeepsBoth(): void
    {
        AccessLogPolicyStandardHilos::initBrowser();

        self::assertTrue(AccessLogPolicy::keepsLog());
        self::assertTrue(AccessLogPolicy::keepsSessionAddress());
    }

    public function testDeviatingFromTheAccessLogClauseKeepsNoLog(): void
    {
        AccessLogPolicyNoLogHilos::initBrowser();

        self::assertFalse(AccessLogPolicy::keepsLog());
        self::assertTrue(AccessLogPolicy::keepsSessionAddress());
    }

    public function testDeviatingFromTheSessionDataClauseKeepsNoSessionAddress(): void
    {
        AccessLogPolicyNoAddressHilos::initBrowser();

        self::assertTrue(AccessLogPolicy::keepsLog());
        self::assertFalse(AccessLogPolicy::keepsSessionAddress());
    }

    /**
     * Only the revision declared last speaks: a deviation an earlier revision carried and the
     * current one dropped no longer switches anything off.
     */
    public function testADeviationOnlyInAnEarlierRevisionSwitchesNothingOff(): void
    {
        AccessLogPolicyDroppedHilos::initBrowser();

        self::assertTrue(AccessLogPolicy::keepsLog());
        self::assertTrue(AccessLogPolicy::keepsSessionAddress());
    }

    public function testTheCutoffIsTwelveMonthsBeforeTheMoment(): void
    {
        $now = (int)mktime(12, 30, 15, 10, 3, 2026);

        self::assertSame('2025-10-03 12:30:15', AccessLogPolicy::cutoff($now));
    }
}

/** The revisions every fixture catalog is built of. */
final class AccessLogPolicyRevisions
{
    public const string FIRST = '2026-09-17';

    public const string SECOND = '2026-09-20';

    /**
     * @param string $id Revision id, also its publication date
     * @param list<string> $clauseKeys Privacy clauses the revision deviates from
     * @return LegalRevision Privacy revision on the first standard set
     */
    public static function privacy(string $id, array $clauseKeys): LegalRevision
    {
        return new LegalRevision(
            LegalDocument::PRIVACY,
            $id,
            $id,
            1,
            LegalSignificance::SUBSTANTIAL,
            $id,
            array_map(
                static fn (string $clauseKey): Deviation => new Deviation(
                    $clauseKey,
                    DeviationDirection::STRICTER,
                    'Differs from the standard',
                    __DIR__ . '/Fixtures/privacy/' . $clauseKey . '.' . self::SECOND . '.txt',
                ),
                $clauseKeys,
            ),
        );
    }
}

/** Declares the terms only. */
final class AccessLogPolicyTermsOnlyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        $on = AccessLogPolicyRevisions::FIRST;

        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(LegalDocument::TERMS, $on, $on, 1, LegalSignificance::SUBSTANTIAL, $on, []),
            ],
        ];
    }
}

/** A privacy text without deviations. */
final class AccessLogPolicyStandardCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::PRIVACY->value => [AccessLogPolicyRevisions::privacy(AccessLogPolicyRevisions::FIRST, [])],
        ];
    }
}

/** A privacy text whose current revision keeps no access log. */
final class AccessLogPolicyNoLogCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::PRIVACY->value => [
                AccessLogPolicyRevisions::privacy(AccessLogPolicyRevisions::FIRST, []),
                AccessLogPolicyRevisions::privacy(AccessLogPolicyRevisions::SECOND, [StandardSetCatalog::CLAUSE_ACCESS_LOG]),
            ],
        ];
    }
}

/** A privacy text whose current revision keeps no address on a session. */
final class AccessLogPolicyNoAddressCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::PRIVACY->value => [
                AccessLogPolicyRevisions::privacy(AccessLogPolicyRevisions::SECOND, [StandardSetCatalog::CLAUSE_SESSION_DATA]),
            ],
        ];
    }
}

/** A privacy text that deviated from both clauses once and returned to the standard. */
final class AccessLogPolicyDroppedCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return [
            LegalDocument::PRIVACY->value => [
                AccessLogPolicyRevisions::privacy(
                    AccessLogPolicyRevisions::FIRST,
                    [StandardSetCatalog::CLAUSE_ACCESS_LOG, StandardSetCatalog::CLAUSE_SESSION_DATA],
                ),
                AccessLogPolicyRevisions::privacy(AccessLogPolicyRevisions::SECOND, []),
            ],
        ];
    }
}

/** Binds the terms-only catalog. */
abstract class AccessLogPolicyTermsOnlyHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AccessLogPolicyTermsOnlyCatalog::class;
}

/** Binds the standard privacy text. */
abstract class AccessLogPolicyStandardHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AccessLogPolicyStandardCatalog::class;
}

/** Binds the text that keeps no access log. */
abstract class AccessLogPolicyNoLogHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AccessLogPolicyNoLogCatalog::class;
}

/** Binds the text that keeps no session address. */
abstract class AccessLogPolicyNoAddressHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AccessLogPolicyNoAddressCatalog::class;
}

/** Binds the text that returned to the standard. */
abstract class AccessLogPolicyDroppedHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = AccessLogPolicyDroppedCatalog::class;
}

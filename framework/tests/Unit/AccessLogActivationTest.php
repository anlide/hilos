<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Entity\Item\AccessLogEntry;
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
 * The start refuses a privacy text that keeps the access log and no address on a session (HIL-1174).
 */
final class AccessLogActivationTest extends TestCase
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

    /** AUTH owes the table the log is written to. */
    public function testAuthRequiresTheAccessLogTable(): void
    {
        self::assertContains(AccessLogEntry::_table, (new AuthFeature())->requirements()->requiredDbTables);
    }

    public function testAStandardLogWithoutASessionAddressIsRefused(): void
    {
        AccessLogActivationNoAddressHilos::initBrowser();

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('standard.session_data');
        AccessLogActivationNoAddressHilos::checkAccessLog();
    }

    /** A text that drops the session address and the log together promises nothing it cannot keep. */
    public function testDroppingTheLogTooIsAccepted(): void
    {
        AccessLogActivationNoLogHilos::initBrowser();

        AccessLogActivationNoLogHilos::checkAccessLog();
        $this->addToAssertionCount(1);
    }

    public function testAProjectWithoutACatalogIsAccepted(): void
    {
        AccessLogActivationStandardHilos::initBrowser();

        AccessLogActivationStandardHilos::checkAccessLog();
        $this->addToAssertionCount(1);
    }

    /** Without sign-in there is no access log to promise anything about. */
    public function testAProjectWithoutAuthIsNotAsked(): void
    {
        AccessLogActivationWithoutAuthHilos::initBrowser();

        AccessLogActivationWithoutAuthHilos::checkAccessLog();
        $this->addToAssertionCount(1);
    }
}

/** Privacy texts deviating from the session clause, alone or with the access log clause. */
final class AccessLogActivationCatalog
{
    /**
     * @param list<string> $clauseKeys Privacy clauses the only revision deviates from
     * @return array<string, list<LegalRevision>> Fixture declarations
     */
    public static function deviatingFrom(array $clauseKeys): array
    {
        $on = '2026-09-20';

        return [
            LegalDocument::PRIVACY->value => [
                new LegalRevision(
                    LegalDocument::PRIVACY,
                    $on,
                    $on,
                    1,
                    LegalSignificance::SUBSTANTIAL,
                    $on,
                    array_map(
                        static fn (string $clauseKey): Deviation => new Deviation(
                            $clauseKey,
                            DeviationDirection::STRICTER,
                            'Differs from the standard',
                            __DIR__ . '/Auth/AccessLog/Fixtures/privacy/' . $clauseKey . '.' . $on . '.txt',
                        ),
                        $clauseKeys,
                    ),
                ),
            ],
        ];
    }
}

/** Keeps the standard access log and no session address. */
final class AccessLogActivationNoAddressCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return AccessLogActivationCatalog::deviatingFrom([StandardSetCatalog::CLAUSE_SESSION_DATA]);
    }
}

/** Keeps neither the access log nor a session address. */
final class AccessLogActivationNoLogCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return AccessLogActivationCatalog::deviatingFrom(
            [StandardSetCatalog::CLAUSE_ACCESS_LOG, StandardSetCatalog::CLAUSE_SESSION_DATA],
        );
    }
}

/** Opens the start check of the access log to the fixtures below. */
abstract class AccessLogActivationHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];

    /**
     * @throws IncompleteFeatureActivationException When the fixture keeps the log and no session address
     */
    public static function checkAccessLog(): void
    {
        static::refuseAccessLogWithoutSessionAddress();
    }
}

/** AUTH with a text that keeps the log and no session address. */
abstract class AccessLogActivationNoAddressHilos extends AccessLogActivationHilos
{
    protected const ?string LEGAL_CATALOG = AccessLogActivationNoAddressCatalog::class;
}

/** AUTH with a text that keeps neither. */
abstract class AccessLogActivationNoLogHilos extends AccessLogActivationHilos
{
    protected const ?string LEGAL_CATALOG = AccessLogActivationNoLogCatalog::class;
}

/** AUTH without a legal catalog. */
abstract class AccessLogActivationStandardHilos extends AccessLogActivationHilos
{
}

/** No sign-in, with the text that would be refused under AUTH. */
abstract class AccessLogActivationWithoutAuthHilos extends AccessLogActivationHilos
{
    protected const array FEATURES = [];
    protected const ?string LEGAL_CATALOG = AccessLogActivationNoAddressCatalog::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\Definition\AnalyticsFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
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
 * Turning analytics on (HIL-1154): the feature owes both journal agents, the start refuses it without
 * a journal directory, and the journal directory is the node's.
 */
final class AnalyticsActivationTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$ac = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    public function testTheFeatureRequiresTheJournalAgentAndTheWriter(): void
    {
        $requirements = (new AnalyticsFeature())->requirements();

        self::assertSame(
            [HilosAgentType::HILOS_ANALYTICS_JOURNAL, HilosAgentType::HILOS_ANALYTICS_WRITER],
            $requirements->requiredAgents,
        );
        self::assertSame(HilosFeature::ANALYTICS, (new AnalyticsFeature())->feature());
        self::assertSame([AnalyticsSettingsCatalog::class], $requirements->requiredCatalogFragments);
    }

    public function testTheRegistryKnowsTheFeature(): void
    {
        $features = array_map(
            static fn($definition): HilosFeature => $definition->feature(),
            (new FeatureRegistry())->all(),
        );

        self::assertContains(HilosFeature::ANALYTICS, $features);
    }

    /**
     * @throws IncompleteFeatureActivationException When the fixture registers no journal directory
     */
    public function testAProjectWithAnalyticsAndNoJournalDirectoryIsRefused(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = null;
        try {
            $this->expectException(IncompleteFeatureActivationException::class);
            $this->expectExceptionMessage('analytics_journal');
            AnalyticsActivationHilos::checkDirectory();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    /**
     * @throws IncompleteFeatureActivationException When the fixture registers no journal directory
     */
    public function testARegisteredJournalDirectoryPasses(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = new class extends FsContext {
            /** Registers an intentionally uncreated path: the journal agent creates it on its start. */
            public function configure(): void
            {
                $this->registerDirectory(self::ANALYTICS_JOURNAL, '/uncreated/analytics-journal', DirectoryScope::NODE);
            }
        };
        Hilos::$fs->configure();
        try {
            AnalyticsActivationHilos::checkDirectory();
            self::assertSame([], Hilos::$fs->declarationErrors());
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    public function testAJournalDirectoryDeclaredTheClustersIsAFault(): void
    {
        $fs = new class extends FsContext {
            /** Declares the node's journal on a volume every node shares. */
            public function configure(): void
            {
                $this->registerDirectory(self::ANALYTICS_JOURNAL, '/shared/analytics-journal', DirectoryScope::CLUSTER);
            }
        };
        $fs->configure();

        self::assertSame(
            ["FS directory [analytics_journal] is the node's: register it with DirectoryScope::NODE"],
            $fs->declarationErrors(),
        );
    }

    /** @throws IncompleteFeatureActivationException When the Privacy declaration is absent */
    public function testAnalyticsWithoutAPrivacyRevisionIsRefused(): void
    {
        AnalyticsActivationHilos::initBrowser();

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('standard.deletion');
        AnalyticsActivationHilos::checkPrivacy();
    }

    /** @throws IncompleteFeatureActivationException When a direct collector start has no Privacy declaration */
    public function testCollectorInitializationAlsoRefusesMissingPrivacy(): void
    {
        AnalyticsActivationHilos::initBrowser();

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('standard.deletion');
        AnalyticsActivationHilos::initAnalytics();
    }

    /** @throws IncompleteFeatureActivationException When the current revision drops the declaration */
    public function testOnlyTheCurrentPrivacyRevisionCounts(): void
    {
        AnalyticsActivationOldPrivacyHilos::initBrowser();

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('standard.deletion');
        AnalyticsActivationOldPrivacyHilos::checkPrivacy();
    }

    public function testCurrentPrivacyDeclarationAllowsCollectorInitialization(): void
    {
        AnalyticsActivationDeclaredHilos::initBrowser();

        AnalyticsActivationDeclaredHilos::checkPrivacy();
        AnalyticsActivationDeclaredHilos::initAnalytics();

        self::assertInstanceOf(AnalyticsCollector::class, Hilos::$ac);
    }
}

abstract class AnalyticsActivationHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS];

    /**
     * @throws IncompleteFeatureActivationException When the fixture registers no journal directory
     */
    public static function checkDirectory(): void
    {
        static::refuseAnalyticsWithoutJournalDirectory();
    }

    /** @throws IncompleteFeatureActivationException When Privacy declares no analytics exception */
    public static function checkPrivacy(): void
    {
        static::refuseAnalyticsWithoutPrivacyDeclaration();
    }
}

/** A Privacy catalog with an old declaration and a current revision that omits it. */
final class AnalyticsActivationOldPrivacyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Published Privacy revisions */
    public static function revisions(): array
    {
        return [LegalDocument::PRIVACY->value => [
            self::privacy('2026-09-17', withDeclaration: true),
            self::privacy('2026-10-05', withDeclaration: false),
        ]];
    }

    /**
     * @param string $date Revision publication and effective date
     * @param bool $withDeclaration Whether to declare the analytics deletion exception
     * @return LegalRevision Privacy revision
     */
    public static function privacy(string $date, bool $withDeclaration): LegalRevision
    {
        return new LegalRevision(
            LegalDocument::PRIVACY, $date, $date, 1, LegalSignificance::SUBSTANTIAL, $date,
            $withDeclaration ? [new Deviation(
                StandardSetCatalog::CLAUSE_DELETION,
                DeviationDirection::LOOSER,
                'Numbered analytics remains after deletion',
                __DIR__ . '/Fixtures/privacy/standard.deletion.txt',
            )] : [],
        );
    }
}

/** A current Privacy revision declares the analytics exception. */
final class AnalyticsActivationDeclaredPrivacyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Published Privacy revisions */
    public static function revisions(): array
    {
        return [LegalDocument::PRIVACY->value => [
            AnalyticsActivationOldPrivacyCatalog::privacy('2026-10-05', withDeclaration: true),
        ]];
    }
}

/** Analytics with an old declaration but none in its current Privacy revision. */
abstract class AnalyticsActivationOldPrivacyHilos extends AnalyticsActivationHilos
{
    protected const ?string LEGAL_CATALOG = AnalyticsActivationOldPrivacyCatalog::class;
}

/** Analytics with the current Privacy declaration. */
abstract class AnalyticsActivationDeclaredHilos extends AnalyticsActivationHilos
{
    protected const ?string LEGAL_CATALOG = AnalyticsActivationDeclaredPrivacyCatalog::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Feature\Definition\AnalyticsFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Turning analytics on (HIL-1154): the feature owes both journal agents, the start refuses it without
 * a journal directory, and the journal directory is the node's.
 */
final class AnalyticsActivationTest extends TestCase
{
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
}

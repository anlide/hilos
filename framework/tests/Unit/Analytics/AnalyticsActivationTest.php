<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Hilos\AbstractHilosAnalyticsAgent;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsCommandConstants;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Feature\Definition\AnalyticsFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\HilosFeature;
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
use Hilos\Pages\AbstractHilosAnalyticsPage;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Turning analytics on: the feature owes the section and both journal agents,
 * and the start refuses missing journal storage or privacy declaration.
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

    public function testTheFeatureRequiresTheSectionAndTheJournal(): void
    {
        $requirements = (new AnalyticsFeature())->requirements();

        self::assertSame(
            [HilosAgentType::HILOS_ANALYTICS, HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosAgentType::HILOS_ANALYTICS_WRITER],
            $requirements->requiredAgents,
        );
        self::assertSame([AbstractHilosAnalyticsPage::class], $requirements->requiredPages);
        self::assertSame(HilosFeature::ANALYTICS, (new AnalyticsFeature())->feature());
        self::assertSame([AnalyticsSettingsCatalog::class], $requirements->requiredCatalogFragments);
        self::assertSame(
            [HilosAnalyticsActionsTable::class, HilosAnalyticsBrowserSessionsTable::class],
            $requirements->requiredTables,
        );
    }

    /** @throws IncompleteFeatureActivationException When the fixture has neither section artifact */
    public function testDeclaredAnalyticsWithoutItsSectionIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('agent ' . HilosAgentType::HILOS_ANALYTICS . ' is not registered in AGENTS');
        AnalyticsActivationHilos::validateFeatureActivation();
    }

    /** @throws IncompleteFeatureActivationException When an undeclared section page is registered */
    public function testSectionPageWithoutTheFeatureIsRefused(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('PAGES registers ' . AnalyticsActivationPage::class
            . ' but HilosFeature::ANALYTICS is not declared in FEATURES');
        AnalyticsActivationPageOnlyHilos::validateFeatureActivation();
    }

    /** @throws IncompleteFeatureActivationException When either analytics table is registered without its feature */
    public function testSectionTablesWithoutTheFeatureAreRefused(): void
    {
        foreach ([AnalyticsActivationActionsOnlyHilos::class, AnalyticsActivationSessionsOnlyHilos::class] as $facade) {
            try {
                $facade::validateFeatureActivation();
                self::fail('Analytics table registration requires the Analytics feature');
            } catch (IncompleteFeatureActivationException $exception) {
                self::assertStringContainsString('TABLES registers', $exception->getMessage());
                self::assertStringContainsString('HilosFeature::ANALYTICS is not declared in FEATURES', $exception->getMessage());
            }
        }
    }

    public function testSectionAgentProvidesTheTypedReader(): void
    {
        $agent = new class extends AbstractHilosAnalyticsAgent {
        };

        self::assertInstanceOf(AnalyticsSectionReader::class, $agent->sectionReader());
    }

    public function testBrowserSessionProbeDeclaresItsTestOnlyCommand(): void
    {
        self::assertContains(AnalyticsCommandConstants::TEST_BROWSER_SESSION, AbstractHilosAnalyticsAgent::AGENT_COMMANDS);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(AnalyticsCommandConstants::TEST_BROWSER_SESSION));
    }

    public function testBrowserSessionProbeRejectsMalformedUserIdsWithoutReading(): void
    {
        foreach ([[], ['userId' => null], ['userId' => '7'], ['userId' => ''], ['userId' => 1.5],
            ['userId' => 0], ['userId' => -1], ['userId' => true], ['userId' => []]] as $payload) {
            $agent = new AnalyticsProbeValidationAgent();
            $agent->onSignalCommand(new CommandRequestDTO('probe-invalid', AnalyticsCommandConstants::TEST_BROWSER_SESSION, $payload), '', '');

            self::assertCount(1, $agent->replies);
            self::assertSame('probe-invalid', $agent->replies[0]->correlationId);
            self::assertFalse($agent->replies[0]->isOk());
            self::assertStringContainsString('positive integer userId', $agent->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
        }
    }

    public function testBrowserSessionProbeAnswersUnknownCommandsExactlyOnce(): void
    {
        $agent = new AnalyticsProbeValidationAgent();
        $agent->onSignalCommand(new CommandRequestDTO('probe-unknown', 'analytics:no-such-command'), '', '');

        self::assertCount(1, $agent->replies);
        self::assertSame('probe-unknown', $agent->replies[0]->correlationId);
        self::assertFalse($agent->replies[0]->isOk());
        self::assertStringContainsString('Unknown command', $agent->replies[0]->payload[CommandConstants::FIELD_MESSAGE]);
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

/** Minimal section page registered in the wrong feature state. */
final class AnalyticsActivationPage extends AbstractHilosAnalyticsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS;
}

/** Analytics page without the feature declaration. */
abstract class AnalyticsActivationPageOnlyHilos extends Hilos
{
    public const array PAGES = [AnalyticsActivationPage::PAGE => AnalyticsActivationPage::class];
}

/** Analytics actions table without the feature declaration. */
abstract class AnalyticsActivationActionsOnlyHilos extends Hilos
{
    public const array TABLES = [HilosAnalyticsActionsTable::TABLE => HilosAnalyticsActionsTable::class];
}

/** Analytics browser sessions table without the feature declaration. */
abstract class AnalyticsActivationSessionsOnlyHilos extends Hilos
{
    public const array TABLES = [HilosAnalyticsBrowserSessionsTable::TABLE => HilosAnalyticsBrowserSessionsTable::class];
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

/** A malformed probe must answer before it reaches the database. */
final class AnalyticsProbeValidationAgent extends AbstractHilosAnalyticsAgent
{
    /** @var list<CommandReplyDTO> Replies produced by one command */
    public array $replies = [];

    /** @return AnalyticsSectionReader Never reached by validation cases */
    public function sectionReader(): AnalyticsSectionReader
    {
        TestCase::fail('Invalid requests must not read analytics');
    }

    /** @param CommandReplyDTO $reply Captured reply */
    public function replyToCommand(CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }
}

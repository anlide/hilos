<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for feature activation validation.
 *
 * The features under test are synthetic: the registry seam hands the validator two definitions
 * of its own, so the tests state what the validator does with a requirement rather than what the
 * the real features happen to require today - the real requirements are asserted per project, by
 * each demo's own topology test.
 */
final class FeatureActivationValidatorTest extends TestCase
{
    public function testFullyActivatedFeaturePasses(): void
    {
        FeatureActivationValidHilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testProjectWithoutFeaturesPasses(): void
    {
        FeatureActivationEmptyHilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeatureWithoutItsPageIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but no page in PAGES extends ' . FeatureActivationBasePage::class,
        );

        FeatureActivationMissingPageHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithoutItsAgentIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but agent ' . FeatureActivationTestAgent::AGENT_TYPE
            . ' is not registered in AGENTS',
        );

        FeatureActivationMissingAgentHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithAgentMissingItsDaemonIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but AGENTS[' . FeatureActivationTestAgent::AGENT_TYPE
            . '] does not declare both a worker and a daemon class',
        );

        FeatureActivationDaemonlessAgentHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithoutItsTableIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but no table in TABLES extends ' . FeatureActivationBaseTable::class,
        );

        FeatureActivationMissingTableHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithoutItsPageTableBindingIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but PAGE_TABLES binds no page extending '
            . FeatureActivationBasePage::class . ' to ' . FeatureActivationBaseTable::class,
        );

        FeatureActivationUnboundPageHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithoutItsCatalogIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but BACKUP_CATALOG still holds the framework default',
        );

        FeatureActivationCatalogLessHilos::validateFeatureActivation();
    }

    public function testRegisteredArtifactWithoutItsDeclarationIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'PAGES registers ' . FeatureActivationProjectPage::class
            . ' but HilosFeature::SETTINGS is not declared in FEATURES',
        );

        FeatureActivationUndeclaredHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithoutItsSessionsLibraryIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::AUTH is declared but agent ' . HilosAgentType::HILOS_SESSIONS_LIBRARY
            . ' is not registered in AGENTS',
        );

        FeatureActivationHostlessHilos::validateFeatureActivation();
    }

    public function testDeclaredFeatureWithItsSessionsLibraryPasses(): void
    {
        FeatureActivationSessionsLibraryHilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testFeatureWithoutTheFeatureItRequiresIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::NOTIFICATION_DELIVERY is declared but the HilosFeature::SETTINGS it is built on is not',
        );

        FeatureActivationUnmetDependencyHilos::validateFeatureActivation();
    }
}

/**
 * Registry of the two synthetic features the activation tests exercise.
 */
final class FeatureActivationTestRegistry extends FeatureRegistry
{
    /**
     * @return list<FeatureDefinition> The synthetic required and dependent features
     */
    protected function buildDefinitions(): array
    {
        return [
            new FeatureActivationRequiringFeature(),
            new FeatureActivationDependentFeature(),
            new FeatureActivationSessionsLibraryFeature(),
        ];
    }
}

/**
 * Synthetic feature that asks for one of everything a project can register.
 */
final class FeatureActivationRequiringFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Case standing in for a feature with full requirements
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::SETTINGS;
    }

    /**
     * @return FeatureRequirements One page, one agent, one table, one binding and one catalog
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredPages: [FeatureActivationBasePage::class],
            requiredAgents: [FeatureActivationTestAgent::AGENT_TYPE],
            requiredTables: [FeatureActivationBaseTable::class],
            requiredPageTables: [FeatureActivationBasePage::class => FeatureActivationBaseTable::class],
            requiredCatalogConstant: 'BACKUP_CATALOG',
        );
    }
}

/**
 * Synthetic feature that requires nothing of the project except another feature.
 */
final class FeatureActivationDependentFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Case standing in for a feature built on another
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::NOTIFICATION_DELIVERY;
    }

    /**
     * @return FeatureRequirements A single feature dependency
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(requires: [HilosFeature::SETTINGS]);
    }
}

/**
 * Synthetic feature whose only obligation is the sessions library its commands end in.
 */
final class FeatureActivationSessionsLibraryFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Case standing in for a feature that sends frames to the sessions library
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::AUTH;
    }

    /**
     * @return FeatureRequirements The sessions library pair and nothing else
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(requiredAgents: [HilosAgentType::HILOS_SESSIONS_LIBRARY]);
    }
}

/**
 * Framework-side page base class a project page must extend for the synthetic feature.
 */
class FeatureActivationBasePage extends AbstractPage
{
}

/**
 * Project page satisfying the synthetic feature's page requirement.
 */
final class FeatureActivationProjectPage extends FeatureActivationBasePage
{
    public const string PAGE = 'feature_activation_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'feature_activation_agent';
}

/**
 * Framework-side table base class a project table must extend for the synthetic feature.
 */
class FeatureActivationBaseTable extends TableDefinition
{
    /**
     * Returns an empty table snapshot for tests.
     *
     * @param TableQueryDTO $query Query parameters
     * @return TableSnapshotDTO Empty table snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return new TableSnapshotDTO();
    }
}

/**
 * Project table satisfying the synthetic feature's table requirement.
 */
final class FeatureActivationProjectTable extends FeatureActivationBaseTable
{
}

/**
 * Worker of the agent pair the synthetic feature requires.
 */
final class FeatureActivationTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'feature_activation_agent';

    /**
     * No-op stop hook for activation test agents.
     */
    public function onStop(): void
    {
    }
}

/**
 * Daemon of the agent pair the synthetic feature requires.
 */
final class FeatureActivationTestAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'feature_activation_agent';
}

/**
 * Catalog a project points BACKUP_CATALOG at to activate the synthetic feature.
 */
final class FeatureActivationTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Empty catalog
     */
    public static function getCatalog(): array
    {
        return [];
    }
}

/**
 * Facade with the synthetic feature declared and fully activated.
 *
 * Every broken facade below extends this one and takes away exactly one thing, so what each
 * test asserts is the single difference rather than a wall of repeated registries.
 */
class FeatureActivationValidHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::SETTINGS];

    protected const ?string BACKUP_CATALOG = FeatureActivationTestCatalog::class;

    public const string FEATURE_TABLE = 'feature_activation_table';

    public const array PAGES = [
        FeatureActivationProjectPage::PAGE => FeatureActivationProjectPage::class,
    ];

    public const array AGENTS = [
        FeatureActivationTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FeatureActivationTestAgent::class,
            AgentRegistryKey::DAEMON => FeatureActivationTestAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        self::FEATURE_TABLE => FeatureActivationProjectTable::class,
    ];

    public const array PAGE_TABLES = [
        FeatureActivationProjectPage::PAGE => [
            self::FEATURE_TABLE => [],
        ],
    ];

    /**
     * Creates the synthetic feature registry the activation tests validate against.
     *
     * @return FeatureRegistry Synthetic feature registry
     */
    protected static function createFeatureRegistry(): FeatureRegistry
    {
        return new FeatureActivationTestRegistry();
    }

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new FeatureActivationTestDbContext();
    }
}

/**
 * DB context the activation test facades hand back; never configured, never queried.
 */
final class FeatureActivationTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for activation tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Facade that declares nothing and registers nothing.
 */
final class FeatureActivationEmptyHilos extends FeatureActivationValidHilos
{
    protected const array FEATURES = [];

    protected const ?string BACKUP_CATALOG = null;

    public const array PAGES = [];

    public const array AGENTS = [];

    public const array TABLES = [];

    public const array PAGE_TABLES = [];
}

/**
 * Facade that declares the feature but registers no page for it.
 */
final class FeatureActivationMissingPageHilos extends FeatureActivationValidHilos
{
    public const array PAGES = [];

    public const array PAGE_TABLES = [];
}

/**
 * Facade that declares the feature but registers no agent for it.
 */
final class FeatureActivationMissingAgentHilos extends FeatureActivationValidHilos
{
    public const array AGENTS = [];
}

/**
 * Facade that registers the feature's agent worker without its daemon.
 */
final class FeatureActivationDaemonlessAgentHilos extends FeatureActivationValidHilos
{
    public const array AGENTS = [
        FeatureActivationTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FeatureActivationTestAgent::class,
        ],
    ];
}

/**
 * Facade that declares the feature but registers no table for it.
 */
final class FeatureActivationMissingTableHilos extends FeatureActivationValidHilos
{
    public const array TABLES = [];

    public const array PAGE_TABLES = [];
}

/**
 * Facade that registers the feature's page and table but binds neither to the other.
 */
final class FeatureActivationUnboundPageHilos extends FeatureActivationValidHilos
{
    public const array PAGE_TABLES = [];
}

/**
 * Facade that declares the feature while its catalog constant still holds the framework default.
 */
final class FeatureActivationCatalogLessHilos extends FeatureActivationValidHilos
{
    protected const ?string BACKUP_CATALOG = null;
}

/**
 * Facade that registers the feature's artifacts without declaring the feature.
 */
final class FeatureActivationUndeclaredHilos extends FeatureActivationValidHilos
{
    protected const array FEATURES = [];
}

/**
 * Facade that declares the dependent feature without the feature it is built on.
 */
final class FeatureActivationUnmetDependencyHilos extends FeatureActivationValidHilos
{
    protected const array FEATURES = [HilosFeature::NOTIFICATION_DELIVERY];

    protected const ?string BACKUP_CATALOG = null;

    public const array PAGES = [];

    public const array AGENTS = [];

    public const array TABLES = [];

    public const array PAGE_TABLES = [];
}

/**
 * Agent standing in for a project's concrete sessions library.
 *
 * Registered under the framework agent type the feature asks for; what the validator reads
 * is the registry entry, and a fixture that actually resolved a session would need a
 * database, connections and a socket.
 */
final class FeatureActivationSessionsLibraryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SESSIONS_LIBRARY;

    /**
     * No-op stop hook for activation test agents.
     */
    public function onStop(): void
    {
    }
}

/**
 * Daemon of the sessions library agent pair.
 */
final class FeatureActivationSessionsLibraryAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SESSIONS_LIBRARY;
}

/**
 * Facade that declares the sign-in feature and registers the sessions library it asks for.
 */
final class FeatureActivationSessionsLibraryHilos extends FeatureActivationValidHilos
{
    protected const array FEATURES = [HilosFeature::SETTINGS, HilosFeature::AUTH];

    public const array AGENTS = [
        FeatureActivationTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FeatureActivationTestAgent::class,
            AgentRegistryKey::DAEMON => FeatureActivationTestAgentDaemon::class,
        ],
        FeatureActivationSessionsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FeatureActivationSessionsLibraryAgent::class,
            AgentRegistryKey::DAEMON => FeatureActivationSessionsLibraryAgentDaemon::class,
        ],
    ];
}

/**
 * Facade that declares the sign-in feature while registering no sessions library.
 */
final class FeatureActivationHostlessHilos extends FeatureActivationValidHilos
{
    protected const array FEATURES = [HilosFeature::SETTINGS, HilosFeature::AUTH];
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\CommandInterface;
use Hilos\Core\Feature\DeferredFeatureRequirementsValidator;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Runtime\View\Item\RtItem;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the feature requirements that startup cannot check.
 *
 * The features are synthetic, as in {@see FeatureActivationValidatorTest}: the registry seam
 * hands the validator two definitions of its own, so what is under test is how a requirement is
 * answered - a migration, a registered command, a mounted presence source - rather than what the
 * the real features ask for, which each demo's own topology test asserts against its own layout.
 *
 * The migrations are real files in a temporary directory, because the thing being verified is
 * exactly that a directory of SQL is read correctly.
 */
final class DeferredFeatureRequirementsValidatorTest extends TestCase
{
    /** @var list<string> Temporary migration directories created by the running test */
    private array $migrationPaths = [];

    /**
     * Removes the temporary migration directories the test wrote.
     */
    protected function tearDown(): void
    {
        foreach ($this->migrationPaths as $path) {
            foreach (glob($path . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($path);
        }

        $this->migrationPaths = [];
    }

    public function testProjectThatOwesNothingElsePasses(): void
    {
        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsPresentContext::class,
        );

        $this->addToAssertionCount(1);
    }

    public function testUnmigratedTableOfADeclaredFeatureIsReported(): void
    {
        $path = $this->migrationsPath(['001_create_other.sql' => 'CREATE TABLE `unrelated` (`id` INT);']);

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            "HilosFeature::SETTINGS is declared but no migration in {$path} creates table deferred_test_table",
        );

        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $path,
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsPresentContext::class,
        );
    }

    public function testRollbackHalfDoesNotActivateTheTableItRecreates(): void
    {
        // A rollback that rebuilds a table on the way down still leaves the schema without it,
        // so the CREATE it contains must not read as an activation.
        $path = $this->migrationsPath([
            '001_create_deferred_down.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);',
        ]);

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('no migration in ' . $path . ' creates table deferred_test_table');

        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $path,
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsPresentContext::class,
        );
    }

    public function testCommentedOutStatementDoesNotActivateTheTable(): void
    {
        // Migrations open with a prose header, and a statement is sometimes parked in a comment
        // while it is reworked; neither creates a table, so neither may satisfy the check.
        $path = $this->migrationsPath([
            '001_create_deferred.sql' => "-- CREATE TABLE `deferred_test_table` — planned, not written yet\n",
        ]);

        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage('no migration in ' . $path . ' creates table deferred_test_table');

        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $path,
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsPresentContext::class,
        );
    }

    public function testUnregisteredCliCommandOfADeclaredFeatureIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::SETTINGS is declared but ' . CliManager::class
            . ' registers no ' . DeferredRequirementsTestCommand::NAME . ' command',
        );

        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            CliManager::class,
            DeferredRequirementsPresentContext::class,
        );
    }

    public function testRuntimeContextWithoutAPresenceSourceIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::HILOS_USERS is declared but no runtime collection of '
            . DeferredRequirementsAbsentContext::class . ' implements ' . HilosPresenceSource::class,
        );

        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsAbsentContext::class,
        );
    }

    public function testProjectWithoutARuntimeContextCannotSatisfyPresence(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'HilosFeature::HILOS_USERS is declared but no runtime collection of a project without'
            . ' a runtime context implements ' . HilosPresenceSource::class,
        );

        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            DeferredRequirementsTestCliManager::class,
            null,
        );
    }

    public function testProjectThatServesPagesOnTheConnectionBasePasses(): void
    {
        DeferredRequirementsPagedHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsConnectedContext::class,
        );

        $this->addToAssertionCount(1);
    }

    public function testProjectThatServesPagesWithoutFrameworkConnectionsIsReported(): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage(
            'PAGES is not empty but no runtime state collection of '
            . DeferredRequirementsPresentContext::class . ' extends ' . HilosConnections::class,
        );

        DeferredRequirementsPagedHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsPresentContext::class,
        );
    }

    public function testHeadlessProjectIsAskedForNoConnectionsAtAll(): void
    {
        // demo/cluster: PAGES = [], no WebSocket, no connections. The invariant reads the empty
        // PAGES as the project saying it serves no browsers, and asks it for nothing.
        DeferredRequirementsValidHilos::validateDeferredFeatureRequirements(
            $this->migrationsPath(['001_create_deferred.sql' => 'CREATE TABLE `deferred_test_table` (`id` INT);']),
            DeferredRequirementsTestCliManager::class,
            DeferredRequirementsPresentContext::class,
        );

        $this->addToAssertionCount(1);
    }

    public function testProjectWithoutFeaturesReadsNothingAtAll(): void
    {
        // Nothing declared means nothing owed: no directory is scanned, no CLI manager is built
        // and no runtime context is constructed - which is why the arguments below can be junk.
        DeferredRequirementsEmptyHilos::validateDeferredFeatureRequirements(
            '/nonexistent/migrations',
            'NoSuchCliManagerClass',
            'NoSuchRtContextClass',
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Writes a temporary migration directory with the given files.
     *
     * @param array<string, string> $files SQL file contents keyed by file name
     * @return string Path of the created directory
     */
    private function migrationsPath(array $files): string
    {
        $path = sys_get_temp_dir() . '/hilos-deferred-' . count($this->migrationPaths) . '-' . getmypid();
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $this->migrationPaths[] = $path;

        foreach ($files as $name => $sql) {
            file_put_contents($path . '/' . $name, $sql);
        }

        return $path;
    }
}

/**
 * Registry of the two synthetic features the deferred requirement tests exercise.
 */
final class DeferredRequirementsTestRegistry extends FeatureRegistry
{
    /**
     * @return list<FeatureDefinition> The synthetic migrated and presence features
     */
    protected function buildDefinitions(): array
    {
        return [
            new DeferredRequirementsMigratedFeature(),
            new DeferredRequirementsPresenceFeature(),
        ];
    }
}

/**
 * Synthetic feature that owes a migrated table and a registered CLI command.
 */
final class DeferredRequirementsMigratedFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Case standing in for a feature with a table and a command
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::SETTINGS;
    }

    /**
     * @return FeatureRequirements One SQL table and one CLI command
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(
            requiredDbTables: ['deferred_test_table'],
            requiredCliCommands: [DeferredRequirementsTestCommand::NAME],
        );
    }
}

/**
 * Synthetic feature that owes a runtime collection reporting presence.
 */
final class DeferredRequirementsPresenceFeature extends FeatureDefinition
{
    /**
     * @return HilosFeature Case standing in for a feature needing presence
     */
    public function feature(): HilosFeature
    {
        return HilosFeature::HILOS_USERS;
    }

    /**
     * @return FeatureRequirements A presence source and nothing else
     */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements(requiresPresenceSource: true);
    }
}

/**
 * Facade declaring both synthetic features over the synthetic registry.
 */
class DeferredRequirementsValidHilos extends HilosFacade
{
    protected const array FEATURES = [HilosFeature::SETTINGS, HilosFeature::HILOS_USERS];

    /**
     * @return FeatureRegistry Registry of the synthetic features
     */
    protected static function createFeatureRegistry(): FeatureRegistry
    {
        return new DeferredRequirementsTestRegistry();
    }

    /**
     * Creates a no-op DB context; the deferred check never builds a layer.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new DeferredRequirementsTestDbContext();
    }
}

/**
 * Facade of a project that serves pages, over the same synthetic registry.
 *
 * The page class is never resolved here - the invariant reads only whether PAGES is empty, which
 * is the project's own statement that browsers subscribe to it.
 */
final class DeferredRequirementsPagedHilos extends DeferredRequirementsValidHilos
{
    public const array PAGES = ['deferred_page' => 'DeferredRequirementsNoSuchPage'];
}

/**
 * DB context the synthetic facade hands back; never configured, never queried.
 */
final class DeferredRequirementsTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for deferred requirement tests.
     */
    public function configure(): void
    {
    }
}

/**
 * Facade declaring nothing, over the same synthetic registry.
 */
final class DeferredRequirementsEmptyHilos extends DeferredRequirementsValidHilos
{
    protected const array FEATURES = [];
}

/**
 * CLI manager registering the command the synthetic feature is driven by.
 */
final class DeferredRequirementsTestCliManager extends CliManager
{
    /**
     * Registers the synthetic feature's command.
     */
    protected function registerProjectCommands(): void
    {
        $this->addCommand(new DeferredRequirementsTestCommand());
    }
}

/**
 * Command the synthetic feature requires; it is never executed.
 */
final class DeferredRequirementsTestCommand implements CommandInterface
{
    public const string NAME = 'deferred:run';

    /**
     * @param array<string, mixed> $options Parsed command options
     * @param list<string> $args Positional arguments
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        return 0;
    }

    /**
     * @return string Command name
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Deferred requirement test command';
    }

    /**
     * @return string Help text
     */
    public function getHelp(): string
    {
        return self::NAME;
    }
}

/**
 * Runtime context whose collection reports presence, as a project with users has.
 */
final class DeferredRequirementsPresentContext extends RtContext
{
    public const string presences = 'deferredPresences';

    /**
     * Registers the presence-reporting collection and its view representation.
     *
     * @throws StateCollectionNotFoundException When the collection is represented before it is mounted
     */
    public function configure(): void
    {
        $this->_stateCollections[self::presences] = DeferredRequirementsStates::init();
        $this->setRepresent(self::presences, DeferredRequirementsPresenceCollection::class);
    }
}

/**
 * Runtime context of a project that serves pages: presence and connections on the base.
 */
final class DeferredRequirementsConnectedContext extends RtContext
{
    public const string presences = 'deferredPresences';
    public const string connections = 'deferredConnections';

    /**
     * Registers the presence-reporting collection and the connections the pages need.
     *
     * @throws StateCollectionNotFoundException When a collection is represented before it is mounted
     */
    public function configure(): void
    {
        $this->_stateCollections[self::presences] = DeferredRequirementsStates::init();
        $this->setRepresent(self::presences, DeferredRequirementsPresenceCollection::class);
        $this->_stateCollections[self::connections] = DeferredRequirementsConnections::init();
    }
}

/**
 * Runtime context whose only collection says nothing about presence.
 */
final class DeferredRequirementsAbsentContext extends RtContext
{
    public const string plain = 'deferredPlain';

    /**
     * Registers a collection that does not report presence.
     *
     * @throws StateCollectionNotFoundException When the collection is represented before it is mounted
     */
    public function configure(): void
    {
        $this->_stateCollections[self::plain] = DeferredRequirementsStates::init();
        $this->setRepresent(self::plain, DeferredRequirementsPlainCollection::class);
    }
}

/**
 * Runtime view collection standing in for a project's connections.
 *
 * @extends RtCollection<DeferredRequirementsItem, RtActions>
 */
final class DeferredRequirementsPresenceCollection extends RtCollection implements HilosPresenceSource
{
    /**
     * @param RtState $state Backing state row
     * @return RtItem View item over the row
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        return new DeferredRequirementsItem($state);
    }

    /**
     * @param ?int $userId User id to summarize
     * @return HilosUserPresenceSummary Summary of no active sessions
     */
    public function summaryForUser(?int $userId): HilosUserPresenceSummary
    {
        return new HilosUserPresenceSummary(0);
    }
}

/**
 * Runtime view collection that reports nothing about presence.
 *
 * @extends RtCollection<DeferredRequirementsItem, RtActions>
 */
final class DeferredRequirementsPlainCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state row
     * @return RtItem View item over the row
     */
    protected function createRtItem(RtState &$state): RtItem
    {
        return new DeferredRequirementsItem($state);
    }
}

/**
 * Minimal state collection behind the test runtime collections.
 *
 * @extends RtStates<DeferredRequirementsState>
 */
final class DeferredRequirementsStates extends RtStates
{
    public const string STATE_CLASS = DeferredRequirementsState::class;
}

/**
 * Connections state collection on the framework base, as a project serving pages keeps.
 *
 * @extends HilosConnections<DeferredRequirementsConnection>
 */
final class DeferredRequirementsConnections extends HilosConnections
{
    public const string STATE_CLASS = DeferredRequirementsConnection::class;
}

/**
 * Connection row with nothing of its own: what the invariant asks for is the base.
 */
final class DeferredRequirementsConnection extends HilosConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * Minimal state row; the tests never put one in a collection.
 */
final class DeferredRequirementsState extends RtState
{
    /**
     * @param array<string, mixed> $row Row fields
     * @return static Empty state row
     */
    public static function fromRow(array $row): static
    {
        return new static();
    }

    /**
     * @return string Empty id; the tests never store a row
     */
    public function getId(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed> Empty row
     */
    public function toArray(): array
    {
        return [];
    }
}

/**
 * Minimal view item over the test state row.
 *
 * @extends RtItem<DeferredRequirementsState>
 */
final class DeferredRequirementsItem extends RtItem
{
    /**
     * @return array<string, mixed> Empty row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

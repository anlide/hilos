<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for project topology validation.
 */
final class TopologyValidatorTest extends TestCase
{
    protected function tearDown(): void
    {
        HilosFacade::$env = null;
        HilosFacade::$db = null;
        HilosFacade::$setting = null;
        HilosFacade::$rt = null;
        HilosFacade::$table = null;
        HilosFacade::$browser = null;
        HilosFacade::$fs = null;
        HilosFacade::$sr = null;
        HilosFacade::$ac = null;

        parent::tearDown();
    }

    public function testValidTopologyPasses(): void
    {
        TopologyValidHilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    public function testComputedPageRoutesComeFromRegisteredPageClasses(): void
    {
        $this->assertSame(
            [TopologyValidPage::PAGE => TopologyValidPage::SUBSCRIPTION_AGENT_TYPE],
            TopologyValidHilos::getPageRoutes(),
        );
    }

    public function testComputedActionRoutesComeFromRegisteredPageClasses(): void
    {
        $this->assertSame(
            [TopologyValidPage::VALID_ACTION => TopologyValidPage::PAGE],
            TopologyValidHilos::getPageActionRoutes(),
        );
        $this->assertSame(
            [TopologyValidPage::VALID_ACTION => TopologyTestActionPayloadDTO::class],
            TopologyValidHilos::getActionDtoRoutes(),
        );
        $this->assertSame(
            [TopologyValidPage::VALID_ACTION => TopologyValidPage::SUBSCRIPTION_AGENT_TYPE],
            TopologyValidHilos::getActionAgentRoutes(),
        );
    }

    public function testComputedPageSignalRoutesComeFromRegisteredPageClasses(): void
    {
        $this->assertSame(
            [
                SignalTypeConstants::FRAME_BINARY => TopologyValidPage::PAGE,
                SignalTypeConstants::AGENT_SIGNAL => [
                    TopologyValidPage::VALID_PAGE_SIGNAL => TopologyValidPage::PAGE,
                ],
            ],
            TopologyValidHilos::getPageSignalRoutes(),
        );
        $this->assertSame(
            [
                SignalTypeConstants::FRAME_BINARY => TopologyValidPage::SUBSCRIPTION_AGENT_TYPE,
                SignalTypeConstants::AGENT_SIGNAL => [
                    TopologyValidPage::VALID_PAGE_SIGNAL => TopologyValidPage::SUBSCRIPTION_AGENT_TYPE,
                ],
            ],
            TopologyValidHilos::getPageSignalAgentRoutes(),
        );
        $this->assertSame([], TopologyValidHilos::getPageSignalDtoRoutes());
    }

    public function testComputedPageSignalDtoRoutesComeFromRegisteredPageClasses(): void
    {
        $this->assertSame(
            [
                SignalTypeConstants::AGENT_SIGNAL => [
                    TopologyPageSignalDtoPage::VALID_PAGE_SIGNAL => TopologyTestPageSignalData::class,
                ],
            ],
            TopologyPageSignalDtoHilos::getPageSignalDtoRoutes(),
        );
        $this->assertSame(
            [
                SignalTypeConstants::AGENT_SIGNAL => [
                    TopologyPageSignalDtoPage::VALID_PAGE_SIGNAL => TopologyPageSignalDtoPage::PAGE,
                ],
            ],
            TopologyPageSignalDtoHilos::getPageSignalRoutes(),
        );

        TopologyPageSignalDtoHilos::validateTopology();
    }

    public function testPageSignalDtoMustImplementSignalDataInterface(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(SignalDataInterface::class);

        TopologyInvalidPageSignalDtoHilos::validateTopology();
    }

    public function testComputedAgentSignalRoutesComeFromRegisteredAgentClasses(): void
    {
        $this->assertSame(
            [TopologyValidAgent::VALID_AGENT_SIGNAL => TopologyValidAgent::AGENT_TYPE],
            TopologyValidHilos::getAgentSignalRoutes(),
        );
        $this->assertSame([], TopologyValidHilos::getAgentSignalDtoRoutes());
    }

    public function testComputedAgentSignalDtoRoutesComeFromRegisteredAgentClasses(): void
    {
        $this->assertSame(
            [
                TopologyAgentSignalDtoAgent::SINGLETON_SIGNAL => TopologyTestAgentSignalData::class,
                TopologyAgentSignalDtoAgent::INDEXED_SIGNAL => TopologyTestIndexedAgentSignalData::class,
            ],
            TopologyAgentSignalDtoHilos::getAgentSignalDtoRoutes(),
        );
        $this->assertSame(
            [
                TopologyAgentSignalDtoAgent::SINGLETON_SIGNAL => TopologyAgentSignalDtoAgent::AGENT_TYPE,
                TopologyAgentSignalDtoAgent::INDEXED_SIGNAL => TopologyAgentSignalDtoAgent::AGENT_TYPE,
            ],
            TopologyAgentSignalDtoHilos::getAgentSignalRoutes(),
        );

        TopologyAgentSignalDtoHilos::validateTopology();
    }

    public function testAgentSignalDtoMustImplementSignalDataInterface(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(SignalDataInterface::class);

        TopologyInvalidAgentSignalDtoHilos::validateTopology();
    }

    public function testPageRegistryRejectsBrokenPageClasses(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyInvalidPagesHilos::validateTopology();
            },
            [
                'PAGES[wrong_page] key must match',
                'PAGES[legacy_tables_page] class',
                "BROWSER['tables']",
                'PAGES[not_page] class',
                'must extend ' . AbstractPage::class,
            ],
        );
    }

    public function testPageSubscriptionOwnersMustBeNonEmpty(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('PAGES[missing_owner_page] class');
        $this->expectExceptionMessage('must declare a non-empty SUBSCRIPTION_AGENT_TYPE');

        TopologyMissingSubscriptionOwnerHilos::validateTopology();
    }

    public function testPageActionsMustUseNonEmptyActionNameKeys(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('PAGES[invalid_action_page] class');
        $this->expectExceptionMessage('ACTIONS must use non-empty action name keys');

        TopologyInvalidActionHilos::validateTopology();
    }

    public function testPageActionsMustHaveSingleOwner(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Action shared_action is declared by multiple pages');
        $this->expectExceptionMessage(TopologyFirstActionPage::PAGE);
        $this->expectExceptionMessage(TopologySecondActionPage::PAGE);

        TopologyDuplicateActionHilos::validateTopology();
    }

    public function testPageSignalsMustBeNonEmptyStrings(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('PAGES[invalid_signal_page] class');
        $this->expectExceptionMessage('SIGNALS[agent_signal] must contain only non-empty signal names or valid signal DTO map entries');

        TopologyInvalidPageSignalHilos::validateTopology();
    }

    public function testPageSignalsMustHaveSingleOwner(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Page signal agent_signal/shared_signal is declared by multiple pages');
        $this->expectExceptionMessage(TopologyFirstSignalPage::PAGE);
        $this->expectExceptionMessage(TopologySecondSignalPage::PAGE);

        TopologyDuplicatePageSignalHilos::validateTopology();
    }

    public function testAgentRegistryRejectsBrokenAgentClasses(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyInvalidAgentsHilos::validateTopology();
            },
            [
                'AGENTS[wrong_agent] key must match',
                'AGENTS[not_agent][worker] class',
                'must extend ' . AbstractAgent::class,
            ],
        );
    }

    public function testAgentSignalsMustBeNonEmptyStrings(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('AGENTS[invalid_agent_signal_agent] class');
        $this->expectExceptionMessage('AGENT_SIGNALS must contain only non-empty signal names');

        TopologyInvalidAgentSignalHilos::validateTopology();
    }

    public function testAgentSignalsMustHaveSingleOwner(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Agent signal shared_agent_signal is declared by multiple agents');
        $this->expectExceptionMessage(TopologyFirstAgentSignalAgent::AGENT_TYPE);
        $this->expectExceptionMessage(TopologySecondAgentSignalAgent::AGENT_TYPE);

        TopologyDuplicateAgentSignalHilos::validateTopology();
    }

    public function testPageAndAgentSignalsMustNotShareAgentSignalName(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Agent signal valid_page_signal is declared by both page-owned and agent-owned routes');

        TopologyPageAgentSignalConflictHilos::validateTopology();
    }

    public function testComputedCommandRoutesComeFromRegisteredAgentClasses(): void
    {
        $this->assertSame(
            [
                TopologyValidAgent::VALID_COMMAND => TopologyValidAgent::AGENT_TYPE,
                TopologyValidAgent::VALID_DTO_COMMAND => TopologyValidAgent::AGENT_TYPE,
            ],
            TopologyValidHilos::getCommandAgentRoutes(),
        );
        $this->assertSame(
            [TopologyValidAgent::VALID_DTO_COMMAND => CommandRequestDTO::class],
            TopologyValidHilos::getCommandDtoRoutes(),
        );
    }

    public function testAgentCommandsMustBeNonEmptyStrings(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('AGENTS[invalid_agent_command_agent] class');
        $this->expectExceptionMessage('AGENT_COMMANDS must contain only non-empty command names');

        TopologyInvalidAgentCommandHilos::validateTopology();
    }

    public function testAgentCommandsMustHaveSingleOwner(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('Command shared_agent_command is declared by multiple agents');
        $this->expectExceptionMessage(TopologyFirstAgentCommandAgent::AGENT_TYPE);
        $this->expectExceptionMessage(TopologySecondAgentCommandAgent::AGENT_TYPE);

        TopologyDuplicateAgentCommandHilos::validateTopology();
    }

    public function testAgentCommandDtoMustImplementSignalDataInterface(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('AGENT_COMMANDS[bad_dto_command] class');
        $this->expectExceptionMessage('must implement ' . SignalDataInterface::class);

        TopologyBadCommandDtoHilos::validateTopology();
    }

    public function testIndexedAgentSignalPassesValidation(): void
    {
        TopologyIndexedAgentSignalHilos::validateTopology();

        $this->assertSame(
            [
                TopologyIndexedAgent::SINGLETON_SIGNAL => TopologyIndexedAgent::AGENT_TYPE,
                TopologyIndexedAgent::INDEXED_SIGNAL => TopologyIndexedAgent::AGENT_TYPE,
            ],
            TopologyIndexedAgentSignalHilos::getAgentSignalRoutes(),
        );
        $this->assertSame(
            [TopologyIndexedAgent::INDEXED_SIGNAL => 'entityId'],
            TopologyIndexedAgentSignalHilos::getAgentSignalIndexFields(),
        );
    }

    public function testIndexedAgentSignalMissingIndexFieldFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(AgentSignalConfigKey::INDEX_FIELD);

        TopologyIndexedAgentMissingIndexFieldHilos::validateTopology();
    }

    public function testIndexedAgentSignalEmptyIndexFieldFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(AgentSignalConfigKey::INDEX_FIELD);

        TopologyIndexedAgentEmptyIndexFieldHilos::validateTopology();
    }

    public function testIndexedAgentSignalUnknownConfigKeyFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('unknown_key');

        TopologyIndexedAgentUnknownConfigKeyHilos::validateTopology();
    }

    public function testPageTablesRejectUnknownPages(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyUnknownPageTableReferenceHilos::validateTopology();
            },
            [
                'PAGE_TABLES[missing_page] references a page missing from PAGES',
            ],
        );
    }

    public function testPageTablesRejectUnknownTables(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('PAGE_TABLES[valid_page][missing_table] references a source missing from TABLES, BROWSER_LISTS, BROWSER_TABLES, and BROWSER_DATA');

        TopologyUnknownTableReferenceHilos::validateTopology();
    }

    public function testRegisteredTablesMustExtendTableDefinition(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('TABLES[bad_table] class');
        $this->expectExceptionMessage('must extend ' . TableDefinition::class);

        TopologyInvalidRegisteredTableHilos::validateTopology();
    }

    public function testBrowserTableKeysMustMatchTableConstants(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('BROWSER_TABLES[wrong_browser_table] key must match');

        TopologyInvalidBrowserTableHilos::validateTopology();
    }

    public function testInitRunsTopologyValidationBeforeLayerInitialization(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('PAGES[wrong_page] key must match');

        TopologyInitInvalidHilos::init();
    }

    /**
     * Asserts topology validation fails with every expected message fragment.
     *
     * @param callable(): void $callback Validation call expected to fail
     * @param list<string> $expectedFragments Message fragments that must be present
     */
    private function assertTopologyErrors(callable $callback, array $expectedFragments): void
    {
        try {
            $callback();
        } catch (InvalidTopologyException $exception) {
            foreach ($expectedFragments as $fragment) {
                $this->assertStringContainsString($fragment, $exception->getMessage());
            }
            return;
        }

        $this->fail('Expected topology validation to fail');
    }
}

final class TopologyTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for topology tests.
     */
    public function configure(): void
    {
    }
}

final class TopologyPageSignalDtoPage extends AbstractPage
{
    public const string PAGE = 'page_signal_dto_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const string VALID_PAGE_SIGNAL = 'valid_page_signal_with_dto';

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            self::VALID_PAGE_SIGNAL => TopologyTestPageSignalData::class,
        ],
    ];
}

final class TopologyInvalidPageSignalDtoPage extends AbstractPage
{
    public const string PAGE = 'invalid_page_signal_dto_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            'invalid_page_signal_dto' => TopologyTestActionPayloadDTO::class,
        ],
    ];
}

final class TopologyValidPage extends AbstractPage
{
    public const string PAGE = 'valid_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const string VALID_ACTION = 'valid_action';

    public const string VALID_PAGE_SIGNAL = 'valid_page_signal';

    public const array ACTIONS = [
        self::VALID_ACTION => TopologyTestActionPayloadDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::FRAME_BINARY => [],
        SignalTypeConstants::AGENT_SIGNAL => [
            self::VALID_PAGE_SIGNAL,
        ],
    ];

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => 'valid_signal',
    ];
}

abstract class TopologyTestAgent extends AbstractAgent
{
    /**
     * No-op stop hook for topology test agents.
     */
    public function onStop(): void
    {
    }
}

final class TopologyValidAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'valid_agent';

    public const string VALID_AGENT_SIGNAL = 'valid_agent_signal';

    public const string VALID_COMMAND = 'valid_command';

    public const string VALID_DTO_COMMAND = 'valid_dto_command';

    public const array AGENT_SIGNALS = [
        self::VALID_AGENT_SIGNAL,
    ];

    public const array AGENT_COMMANDS = [
        self::VALID_COMMAND,
        self::VALID_DTO_COMMAND => CommandRequestDTO::class,
    ];
}

final class TopologyMissingSubscriptionOwnerPage extends AbstractPage
{
    public const string PAGE = 'missing_owner_page';
}

final class TopologyMismatchedPage extends AbstractPage
{
    public const string PAGE = 'actual_page';
}

final class TopologyInvalidActionPage extends AbstractPage
{
    public const string PAGE = 'invalid_action_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array ACTIONS = [
        '' => TopologyTestActionPayloadDTO::class,
        'invalid_dto_action' => 42,
    ];
}

final class TopologyFirstActionPage extends AbstractPage
{
    public const string PAGE = 'first_action_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array ACTIONS = [
        'shared_action' => TopologyTestActionPayloadDTO::class,
    ];
}

final class TopologySecondActionPage extends AbstractPage
{
    public const string PAGE = 'second_action_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array ACTIONS = [
        'shared_action' => TopologyTestActionPayloadDTO::class,
    ];
}

final class TopologyInvalidPageSignalPage extends AbstractPage
{
    public const string PAGE = 'invalid_signal_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            '',
            42,
        ],
    ];
}

final class TopologyFirstSignalPage extends AbstractPage
{
    public const string PAGE = 'first_signal_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            'shared_signal',
        ],
    ];
}

final class TopologySecondSignalPage extends AbstractPage
{
    public const string PAGE = 'second_signal_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SIGNALS = [
        SignalTypeConstants::AGENT_SIGNAL => [
            'shared_signal',
        ],
    ];
}

final class TopologyLegacyTablesPage extends AbstractPage
{
    public const string PAGE = 'legacy_tables_page';

    public const array BROWSER = [
        BrowserConfigKey::TABLES => [],
    ];
}

final class TopologyNotPage
{
}

final class TopologyMismatchedAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'actual_agent';
}

final class TopologyNotAgent
{
}

final class TopologyInvalidAgentSignalAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'invalid_agent_signal_agent';

    public const array AGENT_SIGNALS = [
        '',
        42,
    ];
}

final class TopologyFirstAgentSignalAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'first_agent_signal_agent';

    public const array AGENT_SIGNALS = [
        'shared_agent_signal',
    ];
}

final class TopologySecondAgentSignalAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'second_agent_signal_agent';

    public const array AGENT_SIGNALS = [
        'shared_agent_signal',
    ];
}

final class TopologyConflictingAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'conflicting_agent';

    public const array AGENT_SIGNALS = [
        TopologyValidPage::VALID_PAGE_SIGNAL,
    ];
}

final class TopologyInvalidAgentCommandAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'invalid_agent_command_agent';

    public const array AGENT_COMMANDS = [
        '',
        42,
    ];
}

final class TopologyFirstAgentCommandAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'first_agent_command_agent';

    public const array AGENT_COMMANDS = [
        'shared_agent_command',
    ];
}

final class TopologySecondAgentCommandAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'second_agent_command_agent';

    public const array AGENT_COMMANDS = [
        'shared_agent_command',
    ];
}

final class TopologyBadCommandDtoAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'bad_command_dto_agent';

    public const array AGENT_COMMANDS = [
        'bad_dto_command' => TopologyNotAgent::class,
    ];
}

final class TopologyValidTable extends TableDefinition
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

final class TopologyNotTable
{
}

final class TopologyValidBrowserTable
{
    public const string TABLE = 'valid_browser_table';

    public const array BROWSER = [];
}

final class TopologyBadBrowserTable
{
    public const string TABLE = 'actual_browser_table';

    public const array BROWSER = [];
}

final class TopologyPageSignalDtoHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyPageSignalDtoPage::PAGE => TopologyPageSignalDtoPage::class,
    ];

    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidPageSignalDtoHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyInvalidPageSignalDtoPage::PAGE => TopologyInvalidPageSignalDtoPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyValidHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyValidPage::PAGE => TopologyValidPage::class,
    ];

    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
        ],
    ];

    public const array TABLES = [
        'valid_table' => TopologyValidTable::class,
    ];

    public const array BROWSER_TABLES = [
        TopologyValidBrowserTable::TABLE => TopologyValidBrowserTable::class,
    ];

    public const array PAGE_TABLES = [
        TopologyValidPage::PAGE => [
            'valid_table' => [],
            TopologyValidBrowserTable::TABLE => [],
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidPagesHilos extends HilosFacade
{
    public const array PAGES = [
        'wrong_page' => TopologyMismatchedPage::class,
        TopologyLegacyTablesPage::PAGE => TopologyLegacyTablesPage::class,
        'not_page' => TopologyNotPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyMissingSubscriptionOwnerHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyMissingSubscriptionOwnerPage::PAGE => TopologyMissingSubscriptionOwnerPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidActionHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyInvalidActionPage::PAGE => TopologyInvalidActionPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyDuplicateActionHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyFirstActionPage::PAGE => TopologyFirstActionPage::class,
        TopologySecondActionPage::PAGE => TopologySecondActionPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidPageSignalHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyInvalidPageSignalPage::PAGE => TopologyInvalidPageSignalPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyDuplicatePageSignalHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyFirstSignalPage::PAGE => TopologyFirstSignalPage::class,
        TopologySecondSignalPage::PAGE => TopologySecondSignalPage::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidAgentsHilos extends HilosFacade
{
    public const array AGENTS = [
        'wrong_agent' => [
            AgentRegistryKey::WORKER => TopologyMismatchedAgent::class,
            AgentRegistryKey::DAEMON => TopologyMismatchedAgentDaemon::class,
        ],
        'not_agent' => [
            AgentRegistryKey::WORKER => TopologyNotAgent::class,
            AgentRegistryKey::DAEMON => TopologyNotAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidAgentSignalHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyInvalidAgentSignalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyInvalidAgentSignalAgent::class,
            AgentRegistryKey::DAEMON => TopologyInvalidAgentSignalAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyDuplicateAgentSignalHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyFirstAgentSignalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyFirstAgentSignalAgent::class,
            AgentRegistryKey::DAEMON => TopologyFirstAgentSignalAgentDaemon::class,
        ],
        TopologySecondAgentSignalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologySecondAgentSignalAgent::class,
            AgentRegistryKey::DAEMON => TopologySecondAgentSignalAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidAgentCommandHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyInvalidAgentCommandAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyInvalidAgentCommandAgent::class,
            AgentRegistryKey::DAEMON => TopologyInvalidAgentCommandAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyDuplicateAgentCommandHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyFirstAgentCommandAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyFirstAgentCommandAgent::class,
            AgentRegistryKey::DAEMON => TopologyFirstAgentCommandAgentDaemon::class,
        ],
        TopologySecondAgentCommandAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologySecondAgentCommandAgent::class,
            AgentRegistryKey::DAEMON => TopologySecondAgentCommandAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyBadCommandDtoHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyBadCommandDtoAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyBadCommandDtoAgent::class,
            AgentRegistryKey::DAEMON => TopologyBadCommandDtoAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyPageAgentSignalConflictHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyValidPage::PAGE => TopologyValidPage::class,
    ];

    public const array AGENTS = [
        TopologyConflictingAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyConflictingAgent::class,
            AgentRegistryKey::DAEMON => TopologyConflictingAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyUnknownPageTableReferenceHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyValidPage::PAGE => TopologyValidPage::class,
    ];

    public const array PAGE_TABLES = [
        'missing_page' => [],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyUnknownTableReferenceHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyValidPage::PAGE => TopologyValidPage::class,
    ];

    public const array PAGE_TABLES = [
        TopologyValidPage::PAGE => [
            'missing_table' => [],
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidRegisteredTableHilos extends HilosFacade
{
    public const array TABLES = [
        'bad_table' => TopologyNotTable::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidBrowserTableHilos extends HilosFacade
{
    public const array BROWSER_TABLES = [
        'wrong_browser_table' => TopologyBadBrowserTable::class,
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInitInvalidHilos extends HilosFacade
{
    public const array PAGES = [
        'wrong_page' => TopologyMismatchedPage::class,
    ];

    /**
     * This must not run before topology validation.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        throw new RuntimeException('Topology validation did not run before DB initialization');
    }
}

final class TopologyIndexedAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'indexed_agent';

    public const string SINGLETON_SIGNAL = 'indexed_singleton_signal';

    public const string INDEXED_SIGNAL = 'indexed_indexed_signal';

    public const array AGENT_SIGNALS = [
        self::SINGLETON_SIGNAL,
        self::INDEXED_SIGNAL => [
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
        ],
    ];
}

final class TopologyIndexedAgentSignalHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedAgent::class,
            AgentRegistryKey::DAEMON => TopologyIndexedAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyIndexedAgentMissingIndexField extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'indexed_missing_field_agent';

    public const array AGENT_SIGNALS = [
        'some_indexed_signal' => [],
    ];
}

final class TopologyIndexedAgentMissingIndexFieldHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedAgentMissingIndexField::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedAgentMissingIndexField::class,
            AgentRegistryKey::DAEMON => TopologyIndexedAgentMissingIndexFieldDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyIndexedAgentEmptyIndexField extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'indexed_empty_field_agent';

    public const array AGENT_SIGNALS = [
        'some_indexed_signal' => [
            AgentSignalConfigKey::INDEX_FIELD => '',
        ],
    ];
}

final class TopologyIndexedAgentEmptyIndexFieldHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedAgentEmptyIndexField::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedAgentEmptyIndexField::class,
            AgentRegistryKey::DAEMON => TopologyIndexedAgentEmptyIndexFieldDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyIndexedAgentUnknownConfigKey extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'indexed_unknown_key_agent';

    public const array AGENT_SIGNALS = [
        'some_indexed_signal' => [
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
            'unknown_key' => 'value',
        ],
    ];
}

final class TopologyIndexedAgentUnknownConfigKeyHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedAgentUnknownConfigKey::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedAgentUnknownConfigKey::class,
            AgentRegistryKey::DAEMON => TopologyIndexedAgentUnknownConfigKeyDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyAgentSignalDtoAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'agent_signal_dto_agent';

    public const string SINGLETON_SIGNAL = 'agent_signal_with_dto';

    public const string INDEXED_SIGNAL = 'indexed_agent_signal_with_dto';

    public const array AGENT_SIGNALS = [
        self::SINGLETON_SIGNAL => TopologyTestAgentSignalData::class,
        self::INDEXED_SIGNAL => [
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
            AgentSignalConfigKey::DTO => TopologyTestIndexedAgentSignalData::class,
        ],
    ];
}

final class TopologyInvalidAgentSignalDtoAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'invalid_agent_signal_dto_agent';

    public const array AGENT_SIGNALS = [
        'invalid_agent_signal_dto' => TopologyTestActionPayloadDTO::class,
    ];
}

final class TopologyAgentSignalDtoHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyAgentSignalDtoAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyAgentSignalDtoAgent::class,
            AgentRegistryKey::DAEMON => TopologyAgentSignalDtoAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyInvalidAgentSignalDtoHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyInvalidAgentSignalDtoAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyInvalidAgentSignalDtoAgent::class,
            AgentRegistryKey::DAEMON => TopologyInvalidAgentSignalDtoAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

final class TopologyTestPageSignalData implements SignalDataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }
}

final class TopologyTestAgentSignalData implements SignalDataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }
}

final class TopologyTestIndexedAgentSignalData implements SignalDataInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }
}

final class TopologyTestActionPayloadDTO extends ActionPayloadDTO
{
    /**
     * Creates a no-op topology test action payload DTO.
     *
     * @param array<string, mixed> $data Payload data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }

    /**
     * Returns the topology test action name.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return 'topology_test_action';
    }

    /**
     * Converts the DTO to array.
     *
     * @return array<string, mixed> Empty payload
     */
    public function toArray(): array
    {
        return [];
    }
}

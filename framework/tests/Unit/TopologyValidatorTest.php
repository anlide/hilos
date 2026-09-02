<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Hilos as HilosFacade;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
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

    /**
     * The command config array knows one key, so anything else in it is a typo or a leftover -
     * `testOnly` above all, which was a key until HIL-742 made the name the whole declaration.
     * Left unchecked it would read as a working flag and gate nothing.
     */
    public function testAgentCommandConfigRefusesAKeyItDoesNotKnow(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('AGENT_COMMANDS[configured_command]');
        $this->expectExceptionMessage('unknown config keys: testOnly');

        TopologyUnknownCommandConfigKeyHilos::validateTopology();
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

    /**
     * A node-addressed signal declares no index: it names WHICH node runs the replica, not which
     * of many instances, so the config array is complete without an index field.
     */
    public function testNodeAddressedAgentSignalIsAcceptedWithoutAnIndexField(): void
    {
        TopologyNodeAddressedAgentSignalHilos::validateTopology();

        $this->assertSame(
            [TopologyNodeAddressedAgent::NODE_SIGNAL => 'nodeId'],
            TopologyNodeAddressedAgentSignalHilos::getAgentSignalNodeFields(),
        );
        $this->assertSame([], TopologyNodeAddressedAgentSignalHilos::getAgentSignalIndexFields());
    }

    public function testNodeAddressedAgentSignalEmptyNodeFieldFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(AgentSignalConfigKey::NODE_FIELD);

        TopologyNodeAddressedAgentEmptyNodeFieldHilos::validateTopology();
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

    public function testPerInstancePageRoutesComeFromRegisteredPageClasses(): void
    {
        TopologyPerInstancePageHilos::validateTopology();

        $routes = TopologyPerInstancePageHilos::getPageAgentIndexRoutes();
        $this->assertSame([TopologyPerInstancePage::PAGE], array_keys($routes));
        $this->assertSame(PageAgentIndexSource::PARAM, $routes[TopologyPerInstancePage::PAGE]->source);
        $this->assertSame('entityId', $routes[TopologyPerInstancePage::PAGE]->param);
        $this->assertSame(
            TopologyValidAgent::AGENT_TYPE,
            $routes[TopologyPerInstancePage::PAGE]->fallbackAgentType,
        );
    }

    public function testPerInstanceParamSourceWithoutParamFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_AGENT_INDEX');
        $this->expectExceptionMessage(PageAgentIndexKey::PARAM);

        TopologyPerInstanceMissingParamHilos::validateTopology();
    }

    public function testPerInstanceFallbackAgentTypeMissingFromAgentsFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_AGENT_INDEX');
        $this->expectExceptionMessage('unregistered_agent');
        $this->expectExceptionMessage('missing from AGENTS');

        TopologyPerInstanceUnknownFallbackHilos::validateTopology();
    }

    public function testPerInstanceSessionUserSourceOnPublicPageFails(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_AGENT_INDEX');
        $this->expectExceptionMessage(PageAgentIndexSource::SESSION_USER->value);
        $this->expectExceptionMessage(PageAccessLevel::PUBLIC->value);

        TopologyPerInstancePublicSessionUserHilos::validateTopology();
    }

    public function testPerNodeAgentPassesValidation(): void
    {
        TopologyPerNodeAgentHilos::validateTopology();

        $this->assertTrue(
            AgentRegistry::startsOnEveryNode(TopologyPerNodeAgentHilos::AGENTS[TopologyValidAgent::AGENT_TYPE]),
        );
    }

    public function testPolicyPlacedAgentPassesValidation(): void
    {
        TopologyPolicyPlacedAgentHilos::validateTopology();

        $this->assertSame(
            AgentPlacement::POLICY,
            AgentRegistry::placement(TopologyPolicyPlacedAgentHilos::AGENTS[TopologyValidAgent::AGENT_TYPE]),
        );
    }

    public function testScopeMustBeAnEnumCase(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(AgentRegistryKey::SCOPE . '] must be a ' . AgentScope::class . ' case');

        TopologyScopeNotACaseHilos::validateTopology();
    }

    public function testPlacementMustBeAnEnumCase(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(AgentRegistryKey::PLACEMENT . '] must be a ' . AgentPlacement::class . ' case');

        TopologyPlacementNotACaseHilos::validateTopology();
    }

    public function testNodeScopeCannotCombineWithIndexed(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('cannot combine scope ' . AgentScope::NODE->name . ' with ' . AgentRegistryKey::INDEXED);

        TopologyPerNodeIndexedHilos::validateTopology();
    }

    public function testNodeScopeCannotCarryPlacement(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            'cannot set ' . AgentRegistryKey::PLACEMENT . ' with scope ' . AgentScope::NODE->name,
        );

        TopologyPerNodePlacedHilos::validateTopology();
    }

    public function testIdleWindowedInstanceAgentPassesValidation(): void
    {
        TopologyIdleWindowedAgentHilos::validateTopology();

        $this->assertSame(
            AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
            AgentRegistry::idleTimeout(TopologyIdleWindowedAgentHilos::AGENTS[TopologyIndexedFactoryAgent::AGENT_TYPE]),
        );
    }

    public function testIdleWindowIsRefusedOnANonIndexedAgent(): void
    {
        // A node replica and a set-wide library come up from the bootstrap, and nothing addresses
        // them back into existence - so a window on one reads as an agent that never returns.
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            'cannot set ' . AgentRegistryKey::IDLE_TIMEOUT . ' without ' . AgentRegistryKey::INDEXED,
        );

        TopologyIdleTimeoutWithoutIndexHilos::validateTopology();
    }

    public function testIdleWindowMustBeAPositiveIntegerNumberOfSeconds(): void
    {
        // Refused rather than quietly ignored: a project that wrote '240' meant to declare the
        // policy, and an ignored key would leave the agent living forever with no word said.
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            AgentRegistryKey::IDLE_TIMEOUT . '] must be a positive integer number of seconds',
        );

        TopologyIdleTimeoutNotAnIntHilos::validateTopology();
    }

    public function testIdleWindowOfZeroSecondsIsRefused(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage(
            AgentRegistryKey::IDLE_TIMEOUT . '] must be a positive integer number of seconds',
        );

        TopologyIdleTimeoutNotPositiveHilos::validateTopology();
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
        $this->expectExceptionMessage('PAGE_TABLES[valid_page][missing_table] references a source missing'
            . ' from TABLES, BROWSER_LISTS, BROWSER_TABLES, and BROWSER_DATA');

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
     * A project that replaces the stub registry wholesale passes validation, and the registry the
     * validator let through resolves to that project's words.
     *
     * The four cases after this one pin what the rule refuses; this one pins what it must let
     * through, which {@see testValidTopologyPasses()} does not cover: that facade leaves
     * PROTECTED_MODE_STUB alone, so the registry it validates green is the framework's own single
     * entry, and a rule that refused every override would look just as green.
     *
     * Resolution is asserted next to validation because shape and words are separate questions:
     * the rule only judges that the entries are well formed, while the case worth pinning is that
     * an operation nobody registered falls back to the PROJECT's default and not to the
     * framework's. Both steps take the registry through {@see HilosFacade::catalogConstantOf()},
     * the seam the validator reads it with. What this test does NOT reach is
     * {@see HilosFacade::protectedModeStubRegistry()}, the seam a live maintenance surface
     * resolves through: that one answers off the mounted app class, and this fixture is a facade
     * class the test never mounts.
     */
    public function testProtectedModeStubOverridePassesValidation(): void
    {
        TopologyProtectedModeStubOverrideHilos::validateTopology();

        $registry = HilosFacade::catalogConstantOf(
            TopologyProtectedModeStubOverrideHilos::class,
            'PROTECTED_MODE_STUB',
        );
        $this->assertIsArray($registry);

        $copy = ProtectedModeStubCopy::fromRegistry($registry, 'restore');
        $this->assertSame('Restoring a backup', $copy->title);
        $this->assertSame('The data is being restored.', $copy->message);

        $copy = ProtectedModeStubCopy::fromRegistry($registry, 'reindex');
        $this->assertSame('Project maintenance', $copy->title);
        $this->assertSame('This project is briefly unavailable.', $copy->message);
    }

    public function testProtectedModeStubMissingDefaultEntryFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyProtectedModeStubMissingDefaultHilos::validateTopology();
            },
            [
                'PROTECTED_MODE_STUB is missing the ' . ProtectedModeStubConstants::DEFAULT_OPERATION . ' entry',
            ],
        );
    }

    public function testProtectedModeStubBrokenEntriesFail(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyProtectedModeStubBrokenEntryHilos::validateTopology();
            },
            [
                'PROTECTED_MODE_STUB[' . ProtectedModeStubConstants::DEFAULT_OPERATION . ']['
                    . ProtectedModeStubConstants::TITLE . '] must be a non-empty string',
                'PROTECTED_MODE_STUB[' . ProtectedModeStubConstants::DEFAULT_OPERATION . ']['
                    . ProtectedModeStubConstants::MESSAGE . '] must be a non-empty string',
                'PROTECTED_MODE_STUB[broken_entry_operation] must be an array carrying '
                    . ProtectedModeStubConstants::TITLE . ' and ' . ProtectedModeStubConstants::MESSAGE,
            ],
        );
    }

    public function testProtectedModeStubUnknownEntryFieldFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyProtectedModeStubUnknownFieldHilos::validateTopology();
            },
            [
                'PROTECTED_MODE_STUB[' . ProtectedModeStubConstants::DEFAULT_OPERATION
                    . '] contains unknown entry fields: mesage',
            ],
        );
    }

    public function testProtectedModeStubNonStringOperationKeyFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyProtectedModeStubNumericKeyHilos::validateTopology();
            },
            [
                'PROTECTED_MODE_STUB contains a non-string or empty operation key',
            ],
        );
    }

    public function testPageCatalogEntryMissingATextOrNamingAnUnknownParentFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyPageCatalogBrokenHilos::validateTopology();
            },
            [
                'PAGE_CATALOG[' . TopologyPageCatalogBrokenCatalog::NO_LABEL . ']['
                    . PageCatalogConstants::CATALOG_ENTRY_LABEL . '] must be a non-empty string',
                'PAGE_CATALOG[' . TopologyPageCatalogBrokenCatalog::LOST_PARENT . ']['
                    . PageCatalogConstants::CATALOG_ENTRY_PARENT . '] names no catalog entry',
                'PAGE_CATALOG[' . TopologyPageCatalogBrokenCatalog::NO_LEAD . ']['
                    . PageCatalogConstants::CATALOG_ENTRY_LEAD . '] must be a non-empty string',
            ],
        );
    }

    public function testPageCatalogCycleFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyPageCatalogCycleHilos::validateTopology();
            },
            [
                'PAGE_CATALOG[' . TopologyPageCatalogCycleCatalog::LEFT
                    . '] sits in a parent cycle and never reaches the tree root',
                'PAGE_CATALOG[' . TopologyPageCatalogCycleCatalog::RIGHT
                    . '] sits in a parent cycle and never reaches the tree root',
            ],
        );
    }

    /**
     * The index names the position in the MERGED list, so a project section is judged after the
     * framework ones - which is also where the append order stops being a claim and starts being
     * a fact this test would notice changing.
     */
    public function testPageCatalogSectionItemWithoutAnEntryFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyPageCatalogSectionHilos::validateTopology();
            },
            ['PAGE_CATALOG dashboard section 5 lists an item with no catalog entry'],
        );
    }

    /**
     * The dashboard reads a section's texts unconditionally when it builds its cards, so an
     * untitled section is refused at startup rather than drawn with a blank heading.
     */
    public function testPageCatalogSectionWithoutTextsFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyPageCatalogSectionHilos::validateTopology();
            },
            [
                'PAGE_CATALOG dashboard section 6[' . PageCatalogConstants::SECTION_TITLE
                    . '] must be a non-empty string',
                'PAGE_CATALOG dashboard section 6[' . PageCatalogConstants::SECTION_DESCRIPTION
                    . '] must be a non-empty string',
            ],
        );
    }

    public function testPageCatalogNamingANonProviderFails(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyPageCatalogNotAProviderHilos::validateTopology();
            },
            ['PAGE_CATALOG class ' . TopologyTestDbContext::class . ' must implement '
                . PageCatalogProviderInterface::class],
        );
    }

    public function testWellFormedBrowserDeclarationsPass(): void
    {
        TopologyBrowserValidHilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    public function testPageBrowserDeclarationShapeIsJudged(): void
    {
        $path = 'PAGES[' . TopologyBrowserBrokenPage::PAGE . '] class ' . TopologyBrowserBrokenPage::class
            . '::BROWSER';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserBrokenPageHilos::validateTopology();
            },
            [
                "{$path} declares an unknown key unknown_browser_key",
                "{$path} signal must be a non-empty string",
                "{$path} params[untyped_param] must declare type string or positive_int",
                "{$path} params[unbooled_param] required must be a bool",
                "{$path} guards[0] must declare a known type (db_exists, access, authenticated)",
                "{$path} guards[1] of type access must declare a non-empty field",
                "{$path} guards[2] source must declare type and key",
                "{$path} guards[2] key ref must declare a known type (accept_key, page_param, table_param)",
                "{$path} guards[2] error must be one of 404, 403",
                "{$path} guards[3] of type authenticated must declare neither source nor field",
            ],
        );
    }

    public function testPageGuardKeyMustNameARequiredPageParam(): void
    {
        $path = 'PAGES[' . TopologyBrowserGuardKeyPage::PAGE . '] class ' . TopologyBrowserGuardKeyPage::class
            . '::BROWSER';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserGuardKeyHilos::validateTopology();
            },
            [
                "{$path} guards[0] key page_param botId is not declared in params",
                "{$path} guards[0] key page_param botId must be declared required in params",
                "{$path} guards[1] key page_param tab must be declared required in params",
            ],
        );
    }

    public function testBrowserSourceDeclarationShapeIsJudged(): void
    {
        $path = 'BROWSER_LISTS[' . TopologyBrowserBrokenList::LIST . '] class ' . TopologyBrowserBrokenList::class
            . '::BROWSER';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserBrokenListHilos::validateTopology();
            },
            [
                "{$path} declares an unknown key unknown_source_key",
                "{$path} items[0] source must declare type db or rt",
                "{$path} items[0] must declare a non-empty itemKey",
                "{$path} items[0] where[ownerId] ref must declare a known type"
                    . ' (accept_key, page_param, table_param)',
                "{$path} items[0] where[draftId] table_param missing_param is not declared in params",
                "{$path} sources must list exactly the sources items reference"
                    . ' (missing: memcache:drafts; unused: db:unused_collection)',
            ],
        );
    }

    public function testBrowserDeclarationContainersMustBeArrays(): void
    {
        $pagePath = 'PAGES[' . TopologyBrowserNonArrayPage::PAGE . '] class ' . TopologyBrowserNonArrayPage::class
            . '::BROWSER';
        $tablePath = 'BROWSER_TABLES[' . TopologyBrowserNonArrayTable::TABLE . '] class '
            . TopologyBrowserNonArrayTable::class . '::BROWSER';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserNonArrayHilos::validateTopology();
            },
            [
                "{$pagePath} params must be a map of param declarations",
                "{$pagePath} guards must be a list of guard declarations",
                "{$tablePath} rows[0] fields must be an array",
                "{$tablePath} rows[0] where must be an array",
                "{$tablePath} rows[1] must be an array",
                "{$tablePath} sources must be a list of source declarations",
            ],
        );
    }

    public function testBrowserBindingParamNamesAreCrossChecked(): void
    {
        $path = 'PAGE_LISTS[' . TopologyBrowserBindingPage::PAGE . '][' . TopologyBrowserBindingList::LIST . ']';
        $otherPath = 'PAGE_LISTS[' . TopologyBrowserBindingPage::PAGE . ']['
            . TopologyBrowserBindingOtherList::LIST . ']';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserBrokenBindingHilos::validateTopology();
            },
            [
                "{$path} params[unknown_param] is not declared by " . TopologyBrowserBindingList::class
                    . '::BROWSER params',
                "{$path} params[ownerId] page_param missing_page_param is not declared in params",
                "{$path} does not fill required param viewerId of " . TopologyBrowserBindingList::class . '::BROWSER',
                "{$otherPath} params must be a map of param references",
            ],
        );
    }

    public function testTopologyReferencesRejectSourcesNoLayerMounts(): void
    {
        HilosFacade::$db = new TopologyTestDbContext();
        HilosFacade::$rt = null;
        $tablePath = 'BROWSER_TABLES[' . TopologyBrowserUnmountedTable::TABLE . '] class '
            . TopologyBrowserUnmountedTable::class . '::BROWSER';
        $pagePath = 'PAGES[' . TopologyBrowserUnmountedPage::PAGE . '] class '
            . TopologyBrowserUnmountedPage::class . '::BROWSER';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserUnmountedHilos::validateTopologyReferences();
            },
            [
                "{$tablePath} rows[0] source key owners is not a mounted db collection",
                "{$tablePath} rows[1] names an rt source, but this project mounts no runtime",
                "{$pagePath} guards[0] source key guardians is not a mounted db collection",
            ],
        );
    }

    public function testTopologyReferencesRejectSourcesTheRuntimeDoesNotMount(): void
    {
        HilosFacade::$db = new TopologyTestDbContext();
        $runtime = new TopologyEmptyRtContext();
        $runtime->configure();
        HilosFacade::$rt = $runtime;
        $tablePath = 'BROWSER_TABLES[' . TopologyBrowserUnmountedTable::TABLE . '] class '
            . TopologyBrowserUnmountedTable::class . '::BROWSER';

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserUnmountedHilos::validateTopologyReferences();
            },
            [
                "{$tablePath} rows[1] source key presences is not a mounted rt source",
            ],
        );
    }

    public function testTopologyReferencesAcceptMountedSources(): void
    {
        $db = new TopologyMountedDbContext();
        $db->configure();
        HilosFacade::$db = $db;
        $runtime = new TopologyMountedRtContext();
        $runtime->configure();
        HilosFacade::$rt = $runtime;

        $this->assertFalse(isset($runtime->presences));
        $this->assertTrue($runtime->hasSource('presences'));

        TopologyBrowserMountedHilos::validateTopologyReferences();

        $this->addToAssertionCount(1);
    }

    public function testABrowserJoinByAnUnindexedColumnIsRefused(): void
    {
        $db = new TopologyMountedDbContext();
        $db->configure();
        HilosFacade::$db = $db;
        $runtime = new TopologyMountedRtContext();
        $runtime->configure();
        HilosFacade::$rt = $runtime;

        $this->assertTopologyErrors(
            static function (): void {
                TopologyBrowserUnindexedJoinHilos::validateTopologyReferences();
            },
            [
                "BROWSER_TABLES[browser_unindexed_join_table]: join column 'nickname' of source 'owners'"
                . ' is neither the primary key nor the leftmost column of an index',
            ],
        );
    }

    public function testABrowserJoinByTheLeftmostColumnOfAnIndexIsAccepted(): void
    {
        $db = new TopologyMountedDbContext();
        $db->configure();
        HilosFacade::$db = $db;
        $runtime = new TopologyMountedRtContext();
        $runtime->configure();
        HilosFacade::$rt = $runtime;

        TopologyBrowserIndexedJoinHilos::validateTopologyReferences();

        $this->addToAssertionCount(1);
    }

    public function testInitRunsTopologyReferenceValidationAfterLayerInitialization(): void
    {
        try {
            TopologyBrowserUnmountedHilos::init();
        } catch (InvalidTopologyException $exception) {
            $this->assertStringContainsString(
                'source key owners is not a mounted db collection',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(TopologyTestDbContext::class, HilosFacade::$db);

            return;
        }

        $this->fail('Expected topology reference validation to run once the layers were up');
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

final class TopologyUnknownCommandConfigKeyAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'unknown_command_config_key_agent';

    public const array AGENT_COMMANDS = [
        'configured_command' => ['testOnly' => true],
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

final class TopologyPerNodeAgentHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
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

final class TopologyPolicyPlacedAgentHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
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

final class TopologyScopeNotACaseHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::SCOPE => 'node',
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

final class TopologyPlacementNotACaseHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => 'policy',
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

final class TopologyIdleWindowedAgentHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedFactoryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedFactoryAgent::class,
            AgentRegistryKey::DAEMON => TopologyIndexedFactoryAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
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

final class TopologyIdleTimeoutWithoutIndexHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::IDLE_TIMEOUT => 240,
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

final class TopologyIdleTimeoutNotAnIntHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedFactoryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedFactoryAgent::class,
            AgentRegistryKey::DAEMON => TopologyIndexedFactoryAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => '240',
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

final class TopologyIdleTimeoutNotPositiveHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyIndexedFactoryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyIndexedFactoryAgent::class,
            AgentRegistryKey::DAEMON => TopologyIndexedFactoryAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::IDLE_TIMEOUT => 0,
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

final class TopologyPerNodeIndexedHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
            AgentRegistryKey::INDEXED => true,
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

final class TopologyPerNodePlacedHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
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

final class TopologyUnknownCommandConfigKeyHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyUnknownCommandConfigKeyAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyUnknownCommandConfigKeyAgent::class,
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

final class TopologyNodeAddressedAgent extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'node_addressed_agent';

    public const string NODE_SIGNAL = 'node_addressed_signal';

    public const array AGENT_SIGNALS = [
        self::NODE_SIGNAL => [
            AgentSignalConfigKey::NODE_FIELD => 'nodeId',
        ],
    ];
}

final class TopologyNodeAddressedAgentSignalHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyNodeAddressedAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyNodeAddressedAgent::class,
            AgentRegistryKey::DAEMON => TopologyNodeAddressedAgentDaemon::class,
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

final class TopologyNodeAddressedAgentEmptyNodeField extends TopologyTestAgent
{
    public const string AGENT_TYPE = 'node_addressed_empty_field_agent';

    public const array AGENT_SIGNALS = [
        'some_node_addressed_signal' => [
            AgentSignalConfigKey::NODE_FIELD => '',
        ],
    ];
}

final class TopologyNodeAddressedAgentEmptyNodeFieldHilos extends HilosFacade
{
    public const array AGENTS = [
        TopologyNodeAddressedAgentEmptyNodeField::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyNodeAddressedAgentEmptyNodeField::class,
            AgentRegistryKey::DAEMON => TopologyNodeAddressedAgentEmptyNodeFieldDaemon::class,
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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static();
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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static();
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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static();
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

/**
 * Facade whose stub registry names an operation but drops the default entry.
 *
 * The registry is a protected constant, so every stub case is stated by a facade subclass rather
 * than by handing an array to the validator: a subclass is the only shape in which the validator
 * sees what a project actually declares, and a read taken from the wrong scope sees an empty
 * array instead - which is what these tests turn red on.
 */
final class TopologyProtectedModeStubMissingDefaultHilos extends HilosFacade
{
    protected const array PROTECTED_MODE_STUB = [
        'restore' => [
            ProtectedModeStubConstants::TITLE => 'Restore in progress',
            ProtectedModeStubConstants::MESSAGE => 'The application is briefly unavailable.',
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

final class TopologyProtectedModeStubBrokenEntryHilos extends HilosFacade
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => '',
        ],
        'broken_entry_operation' => 'Maintenance in progress',
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

final class TopologyProtectedModeStubUnknownFieldHilos extends HilosFacade
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
            ProtectedModeStubConstants::MESSAGE => 'The application is briefly unavailable.',
            'mesage' => 'The application is briefly unavailable.',
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

final class TopologyProtectedModeStubNumericKeyHilos extends HilosFacade
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
            ProtectedModeStubConstants::MESSAGE => 'The application is briefly unavailable.',
        ],
        7 => [
            ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
            ProtectedModeStubConstants::MESSAGE => 'The application is briefly unavailable.',
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

/**
 * Facade whose stub registry is replaced wholesale: the project's own default plus one operation.
 *
 * The words differ from the framework's default ({@see HilosFacade::PROTECTED_MODE_STUB}) on
 * purpose - an override that never took effect would still resolve to sentences, and only
 * different ones tell the two apart.
 */
final class TopologyProtectedModeStubOverrideHilos extends HilosFacade
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Project maintenance',
            ProtectedModeStubConstants::MESSAGE => 'This project is briefly unavailable.',
        ],
        'restore' => [
            ProtectedModeStubConstants::TITLE => 'Restoring a backup',
            ProtectedModeStubConstants::MESSAGE => 'The data is being restored.',
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

final class TopologyBrowserValidPage extends AbstractPage
{
    public const string PAGE = 'browser_valid_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => 'browser_valid_signal',
        BrowserConfigKey::PARAMS => [
            'ownerId' => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserGuardKey::KEY => [
                    BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                    BrowserRefKey::KEY => 'ownerId',
                ],
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
            [
                BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED,
            ],
        ],
    ];
}

final class TopologyBrowserValidSourceTable
{
    public const string TABLE = 'browser_valid_source_table';

    public const array BROWSER = [
        BrowserTableConfigKey::PARAMS => [
            'ownerId' => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserTableConfigKey::SOURCES => [
            [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => 'owners',
            ],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::WHERE => [
                    'id' => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => 'ownerId',
                    ],
                ],
                BrowserTableFieldKey::FIELDS => [
                    'id',
                ],
            ],
        ],
    ];
}

final class TopologyBrowserBrokenPage extends AbstractPage
{
    public const string PAGE = 'browser_broken_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => '',
        'unknown_browser_key' => true,
        BrowserConfigKey::PARAMS => [
            'untyped_param' => [
                BrowserParamKey::REQUIRED => true,
            ],
            'unbooled_param' => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => 'yes',
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => 'sudo',
            ],
            [
                BrowserGuardKey::TYPE => BrowserGuardType::ACCESS,
                BrowserGuardKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
            ],
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::KEY => [
                    BrowserRefKey::TYPE => 'route_param',
                ],
                BrowserGuardKey::ERROR => 500,
            ],
            [
                BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED,
                BrowserGuardKey::FIELD => 'admin',
            ],
        ],
    ];
}

final class TopologyBrowserGuardKeyPage extends AbstractPage
{
    public const string PAGE = 'browser_guard_key_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => [
            'tab' => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => false,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserGuardKey::KEY => [
                    BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                    BrowserRefKey::KEY => 'botId',
                ],
            ],
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserGuardKey::KEY => [
                    BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                    BrowserRefKey::KEY => 'tab',
                ],
            ],
        ],
    ];
}

final class TopologyBrowserBrokenList
{
    public const string LIST = 'browser_broken_list';

    public const array BROWSER = [
        'unknown_source_key' => true,
        BrowserListConfigKey::PARAMS => [
            'declared_param' => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => 'unused_collection',
            ],
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => 'memcache',
                    BrowserSourceKey::KEY => 'drafts',
                ],
                BrowserListFieldKey::ITEM_KEY => '',
                BrowserListFieldKey::WHERE => [
                    'ownerId' => [
                        BrowserRefKey::TYPE => 'route_param',
                    ],
                    'draftId' => [
                        BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
                        BrowserRefKey::KEY => 'missing_param',
                    ],
                ],
            ],
        ],
    ];
}

final class TopologyBrowserNonArrayPage extends AbstractPage
{
    public const string PAGE = 'browser_non_array_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array BROWSER = [
        BrowserConfigKey::PARAMS => 'nope',
        BrowserConfigKey::GUARDS => 'nope',
    ];
}

final class TopologyBrowserNonArrayTable
{
    public const string TABLE = 'browser_non_array_table';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => 'nope',
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => 'nope',
                BrowserTableFieldKey::WHERE => 'nope',
            ],
            'not an array',
        ],
    ];
}

final class TopologyBrowserValidHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserValidPage::PAGE => TopologyBrowserValidPage::class,
    ];

    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
        ],
    ];

    public const array BROWSER_TABLES = [
        TopologyBrowserValidSourceTable::TABLE => TopologyBrowserValidSourceTable::class,
    ];

    public const array PAGE_TABLES = [
        TopologyBrowserValidPage::PAGE => [
            TopologyBrowserValidSourceTable::TABLE => [
                BrowserParamKey::PARAMS => [
                    'ownerId' => [
                        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                        BrowserRefKey::KEY => 'ownerId',
                    ],
                ],
            ],
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

final class TopologyBrowserBrokenPageHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserBrokenPage::PAGE => TopologyBrowserBrokenPage::class,
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

final class TopologyBrowserGuardKeyHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserGuardKeyPage::PAGE => TopologyBrowserGuardKeyPage::class,
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

final class TopologyBrowserBrokenListHilos extends HilosFacade
{
    public const array BROWSER_LISTS = [
        TopologyBrowserBrokenList::LIST => TopologyBrowserBrokenList::class,
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

final class TopologyBrowserNonArrayHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserNonArrayPage::PAGE => TopologyBrowserNonArrayPage::class,
    ];

    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
        ],
    ];

    public const array BROWSER_TABLES = [
        TopologyBrowserNonArrayTable::TABLE => TopologyBrowserNonArrayTable::class,
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

final class TopologyBrowserBindingPage extends AbstractPage
{
    public const string PAGE = 'browser_binding_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => 'browser_binding_signal',
        BrowserConfigKey::PARAMS => [
            'ownerId' => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
    ];
}

final class TopologyBrowserBindingList
{
    public const string LIST = 'browser_binding_list';

    public const array BROWSER = [
        BrowserListConfigKey::PARAMS => [
            'ownerId' => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
            'viewerId' => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserListConfigKey::SOURCES => [
            [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => 'owners',
            ],
        ],
        BrowserListConfigKey::ITEMS => [
            [
                BrowserListFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserListFieldKey::ITEM_KEY => 'id',
            ],
        ],
    ];
}

final class TopologyBrowserBindingOtherList
{
    public const string LIST = 'browser_binding_other_list';

    public const array BROWSER = [];
}

final class TopologyBrowserBrokenBindingHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserBindingPage::PAGE => TopologyBrowserBindingPage::class,
    ];

    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
        ],
    ];

    public const array BROWSER_LISTS = [
        TopologyBrowserBindingList::LIST => TopologyBrowserBindingList::class,
        TopologyBrowserBindingOtherList::LIST => TopologyBrowserBindingOtherList::class,
    ];

    public const array PAGE_LISTS = [
        TopologyBrowserBindingPage::PAGE => [
            TopologyBrowserBindingList::LIST => [
                BrowserParamKey::PARAMS => [
                    'unknown_param' => [
                        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                        BrowserRefKey::KEY => 'ownerId',
                    ],
                    'ownerId' => [
                        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                        BrowserRefKey::KEY => 'missing_page_param',
                    ],
                ],
            ],
            TopologyBrowserBindingOtherList::LIST => [
                BrowserParamKey::PARAMS => 'nope',
            ],
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

/**
 * Minimal entity fixture behind the mounted collections, so the join rule has a table to hold a
 * declared column against.
 */
final class TopologyTestEntity extends Entity
{
    public const string _table = 'topology_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id'];
    public const array _types = ['id' => 'integer', 'owner_id' => 'integer'];
    public const array _indexes = ['idx_topology_test_owner' => [Entity::INDEX_COLUMNS => ['owner_id']]];

    public ?int $id = null;
    public ?int $owner_id = null;
}

/**
 * Minimal object fixture wrapping the topology entity.
 */
final class TopologyTestObject extends Object_
{
    public const string ENTITY_CLASS = TopologyTestEntity::class;
}

final class TopologyTestObjects extends Objects
{
    public const string OBJECT_CLASS = TopologyTestObject::class;
}

final class TopologyMountedDbContext extends DbContext
{
    /**
     * Mounts the two empty object collections the reference fixtures name.
     */
    public function configure(): void
    {
        $this->_objectCollections['owners'] = TopologyTestObjects::initEmpty();
        $this->_objectCollections['guardians'] = TopologyTestObjects::initEmpty();
    }
}

final class TopologyEmptyRtContext extends RtContext
{
    /**
     * Mounts no runtime source at all, the state a project has before it declares any.
     */
    public function configure(): void
    {
    }
}

final class TopologyMountedRtContext extends RtContext
{
    /**
     * Declares one item alias and nothing behind it, the state a browser source is judged in:
     * the alias is mounted by the project but resolves to nothing outside a subscription.
     */
    public function configure(): void
    {
        $this->_rtItems['presences'] = [
            'itemClass' => TopologyMountedRtContext::class,
            'itemActionsClass' => null,
        ];
    }
}

final class TopologyBrowserUnmountedPage extends AbstractPage
{
    public const string PAGE = 'browser_unmounted_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => 'browser_unmounted_signal',
        BrowserConfigKey::PARAMS => [
            'ownerId' => [
                BrowserParamKey::TYPE => BrowserParamType::POSITIVE_INT,
                BrowserParamKey::REQUIRED => true,
            ],
        ],
        BrowserConfigKey::GUARDS => [
            [
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'guardians',
                ],
                BrowserGuardKey::KEY => [
                    BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                    BrowserRefKey::KEY => 'ownerId',
                ],
                BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
            ],
        ],
    ];
}

final class TopologyBrowserUnmountedTable
{
    public const string TABLE = 'browser_unmounted_table';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => 'owners',
            ],
            [
                BrowserSourceKey::TYPE => BrowserSourceType::RT,
                BrowserSourceKey::KEY => 'presences',
            ],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
            ],
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => 'presences',
                ],
                BrowserTableFieldKey::ROW_KEY => 'ownerId',
            ],
        ],
    ];
}

final class TopologyBrowserUnmountedHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserUnmountedPage::PAGE => TopologyBrowserUnmountedPage::class,
    ];

    public const array AGENTS = [
        TopologyValidAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => TopologyValidAgent::class,
            AgentRegistryKey::DAEMON => TopologyValidAgentDaemon::class,
        ],
    ];

    public const array BROWSER_TABLES = [
        TopologyBrowserUnmountedTable::TABLE => TopologyBrowserUnmountedTable::class,
    ];

    /**
     * Creates a DB context that mounts nothing, so a source key names no collection.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyTestDbContext();
    }
}

/**
 * Browser table joining a mounted collection by a column the child table has no index for: the
 * declaration the rule exists to refuse, because such a join reads by a full scan.
 */
final class TopologyBrowserUnindexedJoinTable
{
    public const string TABLE = 'browser_unindexed_join_table';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => 'owners',
            ],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserTableFieldKey::ROW_KEY => 'nickname',
            ],
        ],
    ];
}

final class TopologyBrowserUnindexedJoinHilos extends HilosFacade
{
    public const array BROWSER_TABLES = [
        TopologyBrowserUnindexedJoinTable::TABLE => TopologyBrowserUnindexedJoinTable::class,
    ];

    /**
     * Creates a DB context mounting the collections the fixtures name.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyMountedDbContext();
    }
}

/**
 * The same join spelled by VIA, on the column an index of the child table begins with.
 */
final class TopologyBrowserIndexedJoinTable
{
    public const string TABLE = 'browser_indexed_join_table';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            [
                BrowserSourceKey::TYPE => BrowserSourceType::DB,
                BrowserSourceKey::KEY => 'owners',
            ],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => 'owners',
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::VIA => [
                    'ownerId' => 'id',
                ],
            ],
        ],
    ];
}

final class TopologyBrowserIndexedJoinHilos extends HilosFacade
{
    public const array BROWSER_TABLES = [
        TopologyBrowserIndexedJoinTable::TABLE => TopologyBrowserIndexedJoinTable::class,
    ];

    /**
     * Creates a DB context mounting the collections the fixtures name.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyMountedDbContext();
    }
}

final class TopologyBrowserMountedHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyBrowserUnmountedPage::PAGE => TopologyBrowserUnmountedPage::class,
    ];

    public const array BROWSER_TABLES = [
        TopologyBrowserUnmountedTable::TABLE => TopologyBrowserUnmountedTable::class,
    ];

    /**
     * Creates a DB context mounting the collections the fixtures name.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TopologyMountedDbContext();
    }
}


/**
 * Page catalog fixture with two defects: an entry with no caption, and one whose parent is a key
 * the merged catalog does not carry.
 */
final class TopologyPageCatalogBrokenCatalog implements PageCatalogProviderInterface
{
    public const string NO_LABEL = 'broken_no_label';

    public const string LOST_PARENT = 'broken_lost_parent';

    public const string NO_LEAD = 'broken_no_lead';

    /**
     * @return array<string, array<string, mixed>> Pages of the broken fixture
     */
    public static function pages(): array
    {
        return [
            self::NO_LABEL => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => '',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'An entry nobody can name.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            ],
            self::LOST_PARENT => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Orphan',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'An entry hanging off nothing.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => 'no_such_page',
            ],
            self::NO_LEAD => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Mute',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => HilosPageConstants::HILOS_DASHBOARD,
            ],
        ];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> No sections
     */
    public static function dashboardSections(): array
    {
        return [];
    }
}

final class TopologyPageCatalogBrokenHilos extends HilosFacade
{
    protected const string PAGE_CATALOG = TopologyPageCatalogBrokenCatalog::class;

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

/**
 * Page catalog fixture whose two entries name each other as parent, so neither reaches the root.
 */
final class TopologyPageCatalogCycleCatalog implements PageCatalogProviderInterface
{
    public const string LEFT = 'cycle_left';

    public const string RIGHT = 'cycle_right';

    /**
     * @return array<string, array<string, mixed>> Pages of the cyclic fixture
     */
    public static function pages(): array
    {
        return [
            self::LEFT => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Left',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Parent of the right one.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => self::RIGHT,
            ],
            self::RIGHT => [
                PageCatalogConstants::CATALOG_ENTRY_LABEL => 'Right',
                PageCatalogConstants::CATALOG_ENTRY_LEAD => 'Parent of the left one.',
                PageCatalogConstants::CATALOG_ENTRY_PARENT => self::LEFT,
            ],
        ];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> No sections
     */
    public static function dashboardSections(): array
    {
        return [];
    }
}

final class TopologyPageCatalogCycleHilos extends HilosFacade
{
    protected const string PAGE_CATALOG = TopologyPageCatalogCycleCatalog::class;

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

/**
 * Page catalog fixture whose dashboard section lists a page that carries no entry.
 */
final class TopologyPageCatalogSectionCatalog implements PageCatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> No pages
     */
    public static function pages(): array
    {
        return [];
    }

    /**
     * @return list<array{title: string, description: string, items: list<string>}> One broken section
     */
    public static function dashboardSections(): array
    {
        return [
            [
                PageCatalogConstants::SECTION_TITLE => 'Nowhere',
                PageCatalogConstants::SECTION_DESCRIPTION => 'A card pointing at a page nobody declared.',
                PageCatalogConstants::SECTION_ITEMS => ['no_such_page'],
            ],
            [
                PageCatalogConstants::SECTION_TITLE => '',
                PageCatalogConstants::SECTION_DESCRIPTION => '',
                PageCatalogConstants::SECTION_ITEMS => [HilosPageConstants::HILOS_USERS],
            ],
        ];
    }
}

final class TopologyPageCatalogSectionHilos extends HilosFacade
{
    protected const string PAGE_CATALOG = TopologyPageCatalogSectionCatalog::class;

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

final class TopologyPageCatalogNotAProviderHilos extends HilosFacade
{
    protected const string PAGE_CATALOG = TopologyTestDbContext::class;

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

final class TopologyPerInstancePage extends AbstractPage
{
    public const string PAGE = 'per_instance_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => 'entityId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'valid_agent',
    ];
}

final class TopologyPerInstanceMissingParamPage extends AbstractPage
{
    public const string PAGE = 'per_instance_missing_param_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'valid_agent',
    ];
}

final class TopologyPerInstanceUnknownFallbackPage extends AbstractPage
{
    public const string PAGE = 'per_instance_unknown_fallback_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => 'entityId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'unregistered_agent',
    ];
}

final class TopologyPerInstancePublicSessionUserPage extends AbstractPage
{
    public const string PAGE = 'per_instance_public_session_user_page';

    public const string SUBSCRIPTION_AGENT_TYPE = 'valid_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::SESSION_USER,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => 'valid_agent',
    ];
}

final class TopologyPerInstancePageHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyPerInstancePage::PAGE => TopologyPerInstancePage::class,
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

final class TopologyPerInstanceMissingParamHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyPerInstanceMissingParamPage::PAGE => TopologyPerInstanceMissingParamPage::class,
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

final class TopologyPerInstanceUnknownFallbackHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyPerInstanceUnknownFallbackPage::PAGE => TopologyPerInstanceUnknownFallbackPage::class,
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

final class TopologyPerInstancePublicSessionUserHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyPerInstancePublicSessionUserPage::PAGE => TopologyPerInstancePublicSessionUserPage::class,
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

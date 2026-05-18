<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Core\Page\ChatPageFactory;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageTableBindings;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserTableConfig;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the project-level chat topology registry.
 */
final class ChatTopologyRegistryTest extends TestCase
{
    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    public function testRegistryValuesAreClassStrings(): void
    {
        foreach (Hilos::PAGES + Hilos::TABLES + Hilos::BROWSER_TABLES as $class) {
            $this->assertIsString($class);
            $this->assertTrue(class_exists($class), "{$class} must be a concrete class string");
        }
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testBrowserTableRegistryKeysMatchTableClassConstants(): void
    {
        foreach (Hilos::BROWSER_TABLES as $table => $tableClass) {
            $this->assertSame($table, $tableClass::TABLE);
        }
    }

    public function testPageTablesUseRegisteredTableKeys(): void
    {
        foreach (Hilos::PAGE_TABLES as $page => $tables) {
            $this->assertArrayHasKey($page, Hilos::PAGES);

            foreach ($tables as $table => $config) {
                $this->assertTrue(
                    isset(Hilos::TABLES[$table]) || isset(Hilos::BROWSER_TABLES[$table]),
                    "{$page} references unknown table {$table}",
                );
                $this->assertIsArray($config);
            }
        }
    }

    public function testPageBrowserConfigsDoNotDeclareTableBindings(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertArrayNotHasKey(
                BrowserConfigKey::TABLES,
                $pageClass::BROWSER,
                "{$page} must declare page-table bindings in Hilos::PAGE_TABLES",
            );
        }
    }

    public function testChatBrowserContextDoesNotUseLegacyManualTopologyLists(): void
    {
        $reflection = new ReflectionClass(ChatBrowserContext::class);

        $this->assertFalse($reflection->getReflectionConstant('PAGES'));
        $this->assertFalse($reflection->getReflectionConstant('TABLES'));
    }

    public function testChatBrowserContextResolvesPageMetadataFromTopology(): void
    {
        $context = new ChatBrowserContext();
        $resolvePageConfig = \Closure::bind(
            static fn(ChatBrowserContext $context, string $page): ?BrowserPageConfig => $context->resolveBrowserPageConfig($page),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::PAGES as $page => $pageClass) {
            $browserConfig = $pageClass::BROWSER;
            $config = $resolvePageConfig($context, $page);

            $this->assertNotNull($config);
            $this->assertSame($this->expectedSignalName($browserConfig), $config->signalName);
            $this->assertSame($this->expectedPageParams($browserConfig), $config->paramConfigs());
            $this->assertSame($this->expectedPageGuards($browserConfig), $config->guardConfigs());
        }

        $this->assertNull($resolvePageConfig($context, 'missing_page'));
    }

    public function testChatBrowserContextResolvesPageTableBindingsFromTopology(): void
    {
        $context = new ChatBrowserContext();
        $resolvePageTables = \Closure::bind(
            static fn(ChatBrowserContext $context, string $page): BrowserPageTableBindings => $context->resolveBrowserPageTables($page),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::PAGE_TABLES as $page => $tableConfigs) {
            $bindings = iterator_to_array($resolvePageTables($context, $page), false);

            $this->assertSame(array_keys($tableConfigs), array_map(static fn($binding): string => $binding->tableKey, $bindings));
            foreach ($bindings as $binding) {
                $tableConfig = $tableConfigs[$binding->tableKey] ?? [];
                $this->assertSame($this->expectedBindingParamRefs($tableConfig), $binding->paramRefs());
            }
        }

        $this->assertSame([], iterator_to_array($resolvePageTables($context, 'missing_page'), false));
    }

    public function testChatBrowserContextResolvesBrowserOnlyTablesFromTopology(): void
    {
        $context = new ChatBrowserContext();
        $resolveTableConfig = \Closure::bind(
            static fn(ChatBrowserContext $context, string $tableKey): ?BrowserTableConfig => $context->resolveBrowserOnlyTableConfig($tableKey),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::BROWSER_TABLES as $table => $tableClass) {
            $config = $resolveTableConfig($context, $table);

            $this->assertNotNull($config);
            $this->assertSame($this->expectedTableRows($tableClass::BROWSER), $config->rowConfigs());
        }

        $this->assertNull($resolveTableConfig($context, ChatTableContext::settings));
        $this->assertNull($resolveTableConfig($context, 'missing_table'));
    }

    public function testChatPageFactoryCreatesRegisteredPagesFromTopology(): void
    {
        $factory = new ChatPageFactory($this->pageAgent());

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertInstanceOf($pageClass, $factory->getPage($page));
        }
    }

    public function testChatTableContextRegistersTablesFromTopology(): void
    {
        $context = new ChatTableContext();
        $context->configure();

        foreach (Hilos::TABLES as $table => $tableClass) {
            $this->assertInstanceOf($tableClass, $context->get($table));
        }
    }

    /**
     * Creates a minimal page agent for page factory tests.
     */
    private function pageAgent(): PageAgentInterface
    {
        return new class implements PageAgentInterface {
            /**
             * Return the fixture agent id.
             *
             * @return string Agent id
             */
            public function getId(): string
            {
                return 'test-page-agent';
            }

            /**
             * Return the fixture signal source for page helpers.
             *
             * @return SignalSourceInterface Signal source
             */
            public function getAgentSignalSource(): SignalSourceInterface
            {
                return new SignalSource(SignalSource::AGENT, 'test-page-agent');
            }
        };
    }

    /**
     * Extracts the expected browser signal name from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return string Browser signal name
     */
    private function expectedSignalName(array $browserConfig): string
    {
        $signalName = $browserConfig[BrowserConfigKey::SIGNAL] ?? '';

        return is_string($signalName) ? $signalName : '';
    }

    /**
     * Extracts expected route param declarations from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return array<string, mixed> Route param declarations
     */
    private function expectedPageParams(array $browserConfig): array
    {
        $params = $browserConfig[BrowserConfigKey::PARAMS] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Extracts expected guard declarations from a page config.
     *
     * @param array<string, mixed> $browserConfig Page BROWSER config
     * @return list<array<string, mixed>> Guard declarations
     */
    private function expectedPageGuards(array $browserConfig): array
    {
        $guards = $browserConfig[BrowserConfigKey::GUARDS] ?? [];

        return is_array($guards)
            ? array_values(array_filter($guards, static fn(mixed $guard): bool => is_array($guard)))
            : [];
    }

    /**
     * Extracts expected binding param references from a PAGE_TABLES entry.
     *
     * @param mixed $tableConfig Page table binding config
     * @return array<string, mixed> Table param reference declarations
     */
    private function expectedBindingParamRefs(mixed $tableConfig): array
    {
        if (!is_array($tableConfig)) {
            return [];
        }

        $params = $tableConfig[BrowserParamKey::PARAMS] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * Extracts expected row configs from a table BROWSER config.
     *
     * @param array<string, mixed> $browserConfig Table BROWSER config
     * @return list<array<string, mixed>> Row source configs
     */
    private function expectedTableRows(array $browserConfig): array
    {
        $rows = $browserConfig[BrowserConfigKey::ROWS] ?? [];

        return is_array($rows)
            ? array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)))
            : [];
    }
}

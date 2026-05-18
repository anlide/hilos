<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Browser\ChatBrowserContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminModeratorPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\BotPage;
use Demo\Chat\Pages\Hilos\Guardian\GuardianAgentPage;
use Demo\Chat\Pages\Hilos\GuardianPage;
use Demo\Chat\Pages\Hilos\SettingsPage;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage as HilosUsersPage;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Pages\ProfilePage;
use Demo\Chat\Pages\UserPage as ChatUserPage;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the project-level chat topology registry.
 */
final class ChatTopologyRegistryTest extends TestCase
{
    public function testPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::PAGE_ROUTES));
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

    public function testPageTablesPreserveExistingBrowserTableBindings(): void
    {
        foreach ($this->browserTablePages() as $pageClass) {
            $this->assertSame(
                $pageClass::BROWSER[BrowserConfigKey::TABLES],
                Hilos::PAGE_TABLES[$pageClass::PAGE],
            );
        }
    }

    public function testChatBrowserContextDoesNotOwnManualTopologyLists(): void
    {
        $reflection = new ReflectionClass(ChatBrowserContext::class);
        $pageConstant = $reflection->getReflectionConstant('PAGES');
        $tableConstant = $reflection->getReflectionConstant('TABLES');

        $this->assertNotFalse($pageConstant);
        $this->assertNotFalse($tableConstant);
        $this->assertSame(BrowserContext::class, $pageConstant->getDeclaringClass()->getName());
        $this->assertSame(BrowserContext::class, $tableConstant->getDeclaringClass()->getName());
    }

    public function testChatBrowserContextResolvesPageConfigsFromTopology(): void
    {
        $context = new ChatBrowserContext();
        $resolvePageConfig = \Closure::bind(
            static fn(ChatBrowserContext $context, string $page): array => $context->resolveBrowserPageConfig($page),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::PAGES as $page => $pageClass) {
            $expectedConfig = $pageClass::BROWSER;
            if ($expectedConfig !== [] || array_key_exists($page, Hilos::PAGE_TABLES)) {
                $expectedConfig[BrowserConfigKey::TABLES] = Hilos::PAGE_TABLES[$page] ?? [];
            }

            $this->assertSame($expectedConfig, $resolvePageConfig($context, $page));
        }

        $this->assertSame([], $resolvePageConfig($context, 'missing_page'));
    }

    public function testChatBrowserContextResolvesBrowserOnlyTablesFromTopology(): void
    {
        $context = new ChatBrowserContext();
        $resolveTableConfig = \Closure::bind(
            static fn(ChatBrowserContext $context, string $tableKey): ?array => $context->resolveBrowserOnlyTableConfig($tableKey),
            null,
            ChatBrowserContext::class,
        );

        foreach (Hilos::BROWSER_TABLES as $table => $tableClass) {
            $this->assertSame($tableClass::BROWSER, $resolveTableConfig($context, $table));
        }

        $this->assertNull($resolveTableConfig($context, ChatTableContext::settings));
        $this->assertNull($resolveTableConfig($context, 'missing_table'));
    }

    /**
     * @return list<class-string>
     */
    private function browserTablePages(): array
    {
        return [
            MainPage::class,
            ProfilePage::class,
            ChatUserPage::class,
            BotPage::class,
            AdminUsersPage::class,
            AdminModeratorPage::class,
            AdminBotsPage::class,
            SettingsPage::class,
            GuardianPage::class,
            GuardianAgentPage::class,
            HilosUsersPage::class,
            HilosUserPage::class,
        ];
    }
}

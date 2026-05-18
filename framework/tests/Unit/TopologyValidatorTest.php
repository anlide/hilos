<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
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

    public function testPageRoutesAndPageTablesRejectUnknownPages(): void
    {
        $this->assertTopologyErrors(
            static function (): void {
                TopologyUnknownPageReferenceHilos::validateTopology();
            },
            [
                'PAGE_ROUTES[missing_page] references a page missing from PAGES',
                'PAGE_TABLES[missing_page] references a page missing from PAGES',
            ],
        );
    }

    public function testPageTablesRejectUnknownTables(): void
    {
        $this->expectException(InvalidTopologyException::class);
        $this->expectExceptionMessage('PAGE_TABLES[valid_page][missing_table] references a table missing from TABLES and BROWSER_TABLES');

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

final class TopologyValidPage extends AbstractPage
{
    public const string PAGE = 'valid_page';

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => 'valid_signal',
    ];
}

final class TopologyMismatchedPage extends AbstractPage
{
    public const string PAGE = 'actual_page';
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

final class TopologyValidHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyValidPage::PAGE => TopologyValidPage::class,
    ];

    public const array PAGE_ROUTES = [
        TopologyValidPage::PAGE => 'valid_agent',
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

final class TopologyUnknownPageReferenceHilos extends HilosFacade
{
    public const array PAGES = [
        TopologyValidPage::PAGE => TopologyValidPage::class,
    ];

    public const array PAGE_ROUTES = [
        'missing_page' => 'valid_agent',
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

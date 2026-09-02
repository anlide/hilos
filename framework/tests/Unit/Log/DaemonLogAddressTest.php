<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Hilos;
use Hilos\Log\DaemonLogAddress;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the unassertive reading of the daemon log addresses (HIL-843).
 */
final class DaemonLogAddressTest extends TestCase
{
    /** @var ?EnvAccessor Accessor the bootstrap left behind the facade, put back by tearDown() */
    private ?EnvAccessor $originalEnv = null;

    /** @var array<string, string|false> Process values of the two names, as they were before a case */
    private array $originalValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = Hilos::$env;

        // The stack running the suite may export the very names under test, and then a case about
        // an unset address would be measuring the container instead of the class.
        foreach ($this->names() as $name) {
            $this->originalValues[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalValues as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->originalValues = [];

        // Back, not null: the framework bootstrap puts an accessor here for the whole suite, and a
        // case that leaves the facade empty breaks every later test that reads through it.
        Hilos::$env = $this->originalEnv;

        parent::tearDown();
    }

    public function testConfiguredReturnsTheValueTheEnvironmentNames(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=/var/log/hilos/daemon.log');
        $this->useTestEnv();

        $this->assertSame('/var/log/hilos/daemon.log', DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE));
    }

    public function testConfiguredAnswersNullForARequiredNameWithNoValue(): void
    {
        // The point of the class: read outright, this same name raises
        // MissingEnvironmentVariableException and the watchdog dies before the daemon can name it.
        $this->useTestEnv();

        $this->assertNull(DaemonLogAddress::configured(EnvConstants::DAEMON_ERROR_LOG_FILE));
    }

    public function testMissingNamesListsBothAddressesOutputStreamFirst(): void
    {
        $this->useTestEnv();

        $this->assertSame(
            [EnvConstants::DAEMON_LOG_FILE->name, EnvConstants::DAEMON_ERROR_LOG_FILE->name],
            DaemonLogAddress::missingNames(),
        );
    }

    public function testWithoutAnEnvironmentAtAllThereIsNothingToComplainAbout(): void
    {
        Hilos::$env = null;

        $this->assertNull(DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE));
        $this->assertSame([], DaemonLogAddress::missingNames());
    }

    /**
     * Puts a catalog declaring both addresses the way the framework's own does — required strings —
     * behind the facade, so a case measures the class and not the suite's environment.
     */
    private function useTestEnv(): void
    {
        $required = [
            EnvCatalogConstants::CATALOG_ENTRY_TYPE => EnvCatalogConstants::TYPE_STRING,
            EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING => true,
            EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING => true,
        ];

        DaemonLogAddressTestCatalog::$catalog = [
            EnvConstants::DAEMON_LOG_FILE->name => $required,
            EnvConstants::DAEMON_ERROR_LOG_FILE->name => $required,
        ];

        Hilos::$env = new EnvAccessor(DaemonLogAddressTestCatalog::class);
    }

    /**
     * @return list<string> Env names this test writes and clears
     */
    private function names(): array
    {
        return [EnvConstants::DAEMON_LOG_FILE->name, EnvConstants::DAEMON_ERROR_LOG_FILE->name];
    }
}

/**
 * Catalog provider for the accessor the test puts behind the facade.
 *
 * Declared here rather than borrowed from EnvAccessorTest, which keeps its own inside its file.
 */
final class DaemonLogAddressTestCatalog implements CatalogProviderInterface
{
    /** @var array<string, array<string, mixed>> Env catalog */
    public static array $catalog = [];

    /**
     * Returns the current test catalog.
     *
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return self::$catalog;
    }
}

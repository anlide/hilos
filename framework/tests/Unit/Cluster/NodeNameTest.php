<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\NodeName;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogConstants;
use Hilos\Environment\EnvCatalogStub;
use Hilos\Environment\Exception\MissingEnvironmentVariableException;
use Hilos\Environment\Exception\MissingRequiredEnvironmentException;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Node naming rules apply through the real catalog on standalone and cluster nodes alike. */
final class NodeNameTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private string|false $previousName;
    private string|false $previousClusterEnabled;

    /** Isolates the node's name and mode from the invoking stand. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousName = getenv(EnvConstants::CLUSTER_NODE_NAME->name);
        $this->previousClusterEnabled = getenv(EnvConstants::CLUSTER_ENABLED->name);
        Hilos::$env = new EnvAccessor(EnvCatalogStub::class);
        putenv(EnvConstants::CLUSTER_NODE_NAME->name);
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=false');
    }

    /** Restores process values and the facade's accessor. */
    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv(EnvConstants::CLUSTER_NODE_NAME->name . ($this->previousName === false ? '' : '=' . $this->previousName));
        putenv(EnvConstants::CLUSTER_ENABLED->name
            . ($this->previousClusterEnabled === false ? '' : '=' . $this->previousClusterEnabled));
        parent::tearDown();
    }

    public function testCatalogRequiresAPerNodeStringWithoutADefault(): void
    {
        $entry = EnvCatalogStub::getCatalog()[EnvConstants::CLUSTER_NODE_NAME->name];

        $this->assertSame(EnvCatalogConstants::TYPE_STRING, $entry[EnvCatalogConstants::CATALOG_ENTRY_TYPE]);
        $this->assertTrue($entry[EnvCatalogConstants::CATALOG_ENTRY_THROW_IF_MISSING]);
        $this->assertTrue($entry[EnvCatalogConstants::CATALOG_ENTRY_EMPTY_IS_MISSING]);
        $this->assertTrue($entry[EnvCatalogConstants::CATALOG_ENTRY_PER_NODE]);
        $this->assertArrayNotHasKey(EnvCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE, $entry);
        $this->assertFalse(Hilos::$env->sensitiveFor(EnvConstants::CLUSTER_NODE_NAME));
        $this->assertFalse(Hilos::$env->visibleInAdminViewMode(EnvConstants::CLUSTER_NODE_NAME));
    }

    /** @return iterable<string, array{?string, bool}> Missing values in both node modes */
    public static function missingNames(): iterable
    {
        foreach ([false, true] as $cluster) {
            $mode = $cluster ? 'cluster' : 'standalone';
            yield $mode . ' absent' => [null, $cluster];
            yield $mode . ' empty' => ['', $cluster];
            yield $mode . ' whitespace' => ['   ', $cluster];
        }
    }

    /**
     * @param ?string $name Process value, absent when null
     * @param bool $cluster Whether the daemon participates in a cluster
     */
    #[DataProvider('missingNames')]
    public function testMissingNameIsListedByTheStartupCheckAndRefusedByTheReader(?string $name, bool $cluster): void
    {
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=' . ($cluster ? 'true' : 'false'));
        if ($name !== null) {
            putenv(EnvConstants::CLUSTER_NODE_NAME->name . '=' . $name);
        }

        $missing = Hilos::$env->missingRequired();
        $this->assertContains('CLUSTER_NODE_NAME', $missing);
        $this->assertStringContainsString(
            "\n- CLUSTER_NODE_NAME",
            MissingRequiredEnvironmentException::forNames(Hilos::class, $missing)->getMessage(),
        );
        $this->expectException(MissingEnvironmentVariableException::class);
        $this->expectExceptionMessage('CLUSTER_NODE_NAME');

        NodeName::fromEnv();
    }

    public function testEdgesAreTrimmed(): void
    {
        putenv(EnvConstants::CLUSTER_NODE_NAME->name . '=  Главный узел  ');

        $this->assertSame('Главный узел', NodeName::fromEnv());
        $this->assertNotContains('CLUSTER_NODE_NAME', Hilos::$env->missingRequired());
    }

    public function testSixtyFourUnicodeCharactersFitAfterTrimming(): void
    {
        putenv(EnvConstants::CLUSTER_NODE_NAME->name . '= ' . str_repeat('🌍', 64) . ' ');

        $this->assertSame(str_repeat('🌍', 64), NodeName::fromEnv());
    }

    /** @return iterable<string, array{string}> Names violating the length or single-line rule */
    public static function invalidNames(): iterable
    {
        yield '65 Unicode characters' => [str_repeat('🌍', 65)];
        yield 'newline' => ["Main\nnode"];
        yield 'carriage return' => ["Main\rnode"];
        yield 'tab' => ["Main\tnode"];
        yield 'control' => ["Main\x01node"];
        yield 'Unicode control' => ["Main\u{0085}node"];
        yield 'Unicode line separator' => ["Main\u{2028}node"];
        yield 'Unicode paragraph separator' => ["Main\u{2029}node"];
    }

    /** @param string $name Invalid process value */
    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRefusedWithTheKeyAndRule(string $name): void
    {
        putenv(EnvConstants::CLUSTER_NODE_NAME->name . '=' . $name);
        $this->expectException(ClusterConfigurationException::class);
        $this->expectExceptionMessage('CLUSTER_NODE_NAME must be one line of at most 64 characters');

        NodeName::fromEnv();
    }

    /** Unicode spaces, symbols, and dollars are valid inside a single-line name. */
    public function testUnicodeSpacesSymbolsAndDollarsRemainValid(): void
    {
        $name = "Main\u{00A0}node\u{2003}🌍 $";
        putenv(EnvConstants::CLUSTER_NODE_NAME->name . '=' . $name);

        $this->assertSame($name, NodeName::fromEnv());
    }

    public function testLabelIncludesTheNameAndStableId(): void
    {
        $this->assertSame('Master 1 (m1)', NodeName::label('m1', 'Master 1'));
    }

    public function testLabelUsesOnlyTheIdWhenTheNameIsUnknown(): void
    {
        $this->assertSame('m1', NodeName::label('m1', null));
    }
}

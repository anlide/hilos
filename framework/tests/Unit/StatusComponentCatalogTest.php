<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\Hilos;
use Hilos\Status\Exception\StatusComponentDeclarationException;
use Hilos\Status\StatusComponentCatalog;
use Hilos\Status\StatusSettingsCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Component declaration validation without DB, runtime or a running project. */
final class StatusComponentCatalogTest extends TestCase
{
    private const array AGENTS = [
        HilosAgentType::HILOS_INDEX => [],
        'cluster' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER, AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'node' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'indexed' => [AgentRegistryKey::INDEXED => true],
        'idle' => [AgentRegistryKey::IDLE_TIMEOUT => 240],
    ];

    public function testEmptyDeclarationUsesThePermanentIndexAgent(): void
    {
        $catalog = StatusComponentCatalog::fromDeclaration([], self::AGENTS);
        self::assertCount(1, $catalog);
        $component = iterator_to_array($catalog)['service'];
        self::assertSame('service', $component->componentKey);
        self::assertSame('Service', $component->name);
        self::assertNull($component->description);
        self::assertSame(HilosAgentType::HILOS_INDEX, $component->agentType);
        self::assertSame(AgentScope::CLUSTER, $component->scope);
        self::assertSame(AgentPlacement::LEADER, $component->placement);
    }

    public function testOrderMachineKeysAndBothPlacementAxesArePreserved(): void
    {
        $catalog = StatusComponentCatalog::fromDeclaration([
            'node.worker-1' => ['name' => 'Узлы', 'description' => 'Public description', 'agentType' => 'node'],
            'cluster' => ['name' => str_repeat('界', 255), 'description' => null, 'agentType' => 'cluster'],
        ], self::AGENTS);
        $components = iterator_to_array($catalog);
        self::assertSame(['node.worker-1', 'cluster'], array_keys($components));
        self::assertSame(AgentScope::NODE, $components['node.worker-1']->scope);
        self::assertSame(AgentPlacement::LEADER, $components['node.worker-1']->placement);
        self::assertSame(AgentScope::CLUSTER, $components['cluster']->scope);
        self::assertSame(AgentPlacement::POLICY, $components['cluster']->placement);
        self::assertSame('Public description', $components['node.worker-1']->description);
    }

    /** @return iterable<string, array{array<mixed>}> Malformed component maps */
    public static function invalidDeclarations(): iterable
    {
        $valid = ['name' => 'Component', 'description' => null, 'agentType' => 'cluster'];
        foreach (['Upper', 'has space', '1first', 'кириллица', '', str_repeat('a', 129)] as $key) {
            yield 'key ' . $key => [[$key => $valid]];
        }
        yield 'numeric key' => [[0 => $valid]];
        yield 'not an entry' => [['x' => 'text']];
        yield 'missing name' => [['x' => ['description' => null, 'agentType' => 'cluster']]];
        yield 'missing description' => [['x' => ['name' => 'Name', 'agentType' => 'cluster']]];
        yield 'missing type' => [['x' => ['name' => 'Name', 'description' => null]]];
        yield 'extra field' => [['x' => $valid + ['extra' => 1]]];
        foreach (['', '  ', str_repeat('界', 256), "\xff", 12] as $name) {
            yield 'name ' . bin2hex((string)$name) => [['x' => array_replace($valid, ['name' => $name])]];
        }
        yield 'bad description' => [['x' => array_replace($valid, ['description' => false])]];
        yield 'bad UTF-8 description' => [['x' => array_replace($valid, ['description' => "\xff"])]];
        foreach (['missing', 'indexed', 'idle', ''] as $agent) {
            yield 'type ' . $agent => [['x' => array_replace($valid, ['agentType' => $agent])]];
        }
    }

    /** @param array<mixed> $declaration Invalid project declaration */
    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationRefuses(array $declaration): void
    {
        $this->expectException(StatusComponentDeclarationException::class);
        StatusComponentCatalog::fromDeclaration($declaration, self::AGENTS);
    }

    public function testTypedConstructionRefusesDuplicateKeys(): void
    {
        $component = iterator_to_array(StatusComponentCatalog::fromDeclaration([], self::AGENTS))['service'];
        $this->expectException(StatusComponentDeclarationException::class);
        new StatusComponentCatalog($component, $component);
    }

    public function testFallbackStillRequiresAKnownSupportedAgent(): void
    {
        $this->expectException(StatusComponentDeclarationException::class);
        StatusComponentCatalog::fromDeclaration([], []);
    }

    public function testFacadeReadersUseTheNamedAndTheRunningFacadeRespectively(): void
    {
        $previous = Hilos::appClass();
        $binding = new ReflectionProperty(Hilos::class, 'appClass');
        try {
            $binding->setValue(null, StatusCatalogHilos::class);
            self::assertSame('Catalog test', iterator_to_array(Hilos::statusComponents())['test']->name);
            self::assertSame('Service', iterator_to_array(Hilos::statusComponentsOf(StatusFallbackHilos::class))['service']->name);
        } finally {
            $binding->setValue(null, $previous);
        }
    }

    public function testPublicationSettingHasTheExistingIntegerRuleAndNoRuntimeDependency(): void
    {
        self::assertSame([
            StatusSettingsCatalog::PUBLISH_DELAY_SECONDS => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 300,
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
            ],
        ], StatusSettingsCatalog::getCatalog());
    }
}

abstract class StatusCatalogHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
    protected const array STATUS_COMPONENTS = [
        'test' => ['name' => 'Catalog test', 'description' => null, 'agentType' => HilosAgentType::HILOS_INDEX],
    ];
}

abstract class StatusFallbackHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Item\HilosClusterNode as ViewHilosClusterNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runtime node names survive serialization and partial synchronization. */
final class HilosClusterNodeTest extends TestCase
{
    public function testCreateAndRoundtripKeepTheNameInTheExactRow(): void
    {
        $state = HilosClusterNode::create('node-a', 'Главный узел', NodeRole::Master->value, ['gpu'], null, true, 42.0);
        $expected = [
            HilosClusterNode::nodeId => 'node-a',
            HilosClusterNode::name => 'Главный узел',
            HilosClusterNode::role => 'master',
            HilosClusterNode::capabilities => ['gpu'],
            HilosClusterNode::address => null,
            HilosClusterNode::online => true,
            HilosClusterNode::lastSeen => 42.0,
        ];

        self::assertSame('Главный узел', $state->name);
        self::assertSame($expected, $state->toArray());
        $restored = HilosClusterNode::fromRow($state->toArray());
        self::assertSame('Главный узел', $restored->name);
        self::assertSame($expected, $restored->toArray());
        $view = new ViewHilosClusterNode($restored);
        self::assertSame('Главный узел', $view->name);
        self::assertSame($expected, $view->toArray());
    }

    public function testADiffRenamesTheNodeWithoutChangingItsIdentityOrOtherFields(): void
    {
        $state = HilosClusterNode::create('node-a', 'Original name', NodeRole::Master->value, [], null, true, 42.0);

        $state->applyDiff([HilosClusterNode::name => 'Renamed node']);

        self::assertSame('node-a', $state->getId());
        self::assertSame([
            HilosClusterNode::nodeId => 'node-a',
            HilosClusterNode::name => 'Renamed node',
            HilosClusterNode::role => 'master',
            HilosClusterNode::capabilities => [],
            HilosClusterNode::address => null,
            HilosClusterNode::online => true,
            HilosClusterNode::lastSeen => 42.0,
        ], $state->toArray());

        $state->applyDiff([HilosClusterNode::online => false]);

        self::assertSame('Renamed node', $state->name);
        self::assertFalse($state->online);
    }

    public function testAFullRowWithoutANameIsRefused(): void
    {
        $row = HilosClusterNode::create('node-a', 'Original name', NodeRole::Master->value, [], null, true, 42.0)->toArray();
        unset($row[HilosClusterNode::name]);

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HilosClusterNode::name);

        HilosClusterNode::fromRow($row);
    }

    /** @return list<array{0: mixed}> Values that cannot name a runtime node */
    public static function invalidNames(): array
    {
        return [[null], [42], [[]]];
    }

    /** @param mixed $name Value of the wrong type */
    #[DataProvider('invalidNames')]
    public function testAFullRowWithAMistypedNameIsRefused(mixed $name): void
    {
        $row = HilosClusterNode::create('node-a', 'Original name', NodeRole::Master->value, [], null, true, 42.0)->toArray();
        $row[HilosClusterNode::name] = $name;

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HilosClusterNode::name);

        HilosClusterNode::fromRow($row);
    }

    /** @param mixed $name Value of the wrong type */
    #[DataProvider('invalidNames')]
    public function testADiffWithAMistypedNameIsRefused(mixed $name): void
    {
        $state = HilosClusterNode::create('node-a', 'Original name', NodeRole::Master->value, [], null, true, 42.0);

        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HilosClusterNode::name);

        $state->applyDiff([HilosClusterNode::name => $name]);
    }
}

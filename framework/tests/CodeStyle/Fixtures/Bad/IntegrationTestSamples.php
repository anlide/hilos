<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\BreaksOrmState as OrmReset;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests\CycleLeft;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests\DerivedOrmReset;
use PHPUnit\Framework\TestCase;
use Stringable;

#[IntegrationTest]
final class WrongIntegrationBase extends TestCase
{
}

#[BreaksOrmState]
final class OrphanOrmMarker extends TestCase
{
    #[BreaksOrmState]
    public function orphanMethod(): void
    {
    }

    #[IntegrationTest]
    public function misplacedClassMarker(): void
    {
    }
}

#[IntegrationTest]
abstract class MarkedEnvironment extends RollbackIntegrationTestCase
{
}

#[IntegrationTest]
abstract class MarkedOrmEnvironment extends RollbackIntegrationTestCase implements BreaksOrmState
{
}

abstract class PlainEnvironment extends RollbackIntegrationTestCase
{
}

final class UnmarkedDirect extends RollbackIntegrationTestCase
{
}

final class UnmarkedIndirect extends PlainEnvironment
{
}

final class UnmarkedDirectInterface extends RollbackIntegrationTestCase implements BreaksOrmState
{
}

final class UnmarkedAliasedInterface extends RollbackIntegrationTestCase implements OrmReset
{
}

final class UnmarkedInheritedInterface extends RollbackIntegrationTestCase implements DerivedOrmReset
{
}

abstract class UnmarkedOrmEnvironment extends RollbackIntegrationTestCase implements BreaksOrmState
{
}

final class UnmarkedInheritedClass extends UnmarkedOrmEnvironment
{
}

#[IntegrationTest]
#[BreaksOrmState]
final class ObsoleteMarkedAttribute extends RollbackIntegrationTestCase
{
    #[BreaksOrmState]
    public function markedMethodStillObsolete(): void
    {
    }
}

final class UnmarkedCycle extends RollbackIntegrationTestCase implements CycleLeft
{
}

final class UnmarkedMissingInterface extends RollbackIntegrationTestCase implements Stringable
{
}

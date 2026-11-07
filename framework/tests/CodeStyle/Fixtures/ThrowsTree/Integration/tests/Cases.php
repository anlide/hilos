<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests;

use Hilos\Database\Database as Db;
use Hilos\Testing\{BreaksOrmState as Breaks, IntegrationTest as CaseMarker};
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Backend\Product;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use stdClass;

/** @property-read stdClass $observation Attribute groups must not hide this docblock. */
#[Group('integration'), CaseMarker]
final class HelperCase extends MiddleEnvironment implements Breaks
{
    use OuterHelper;

    public function exercise(LocalHelper $helper): void
    {
        $this->inheritedHelper();
        $this->clearRows();
        LocalHelper::unsafe();
        $helper->recurse();
        Product::transact();
    }
}

#[CaseMarker]
final class SharedHelperCase extends MiddleEnvironment
{
    use OuterHelper;

    public function exercise(): void
    {
        LocalHelper::unsafe();
    }
}

#[CaseMarker]
final class ManagedLifecycleCase extends MiddleEnvironment
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    protected function assertPostConditions(): void
    {
    }

    public static function setUpBeforeClass(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    protected function prepareIntegrationEnvironment(): void
    {
    }

    protected function releaseIntegrationEnvironment(): void
    {
    }

    public function exercise(): void
    {
        Db::beginRollbackScopeForTest();
    }
}

#[\Hilos\Testing\IntegrationTest]
final class QualifiedCase extends MiddleEnvironment implements \Hilos\Testing\BreaksOrmState
{
    public function exercise(): void
    {
        Db::transactionCommit();
    }
}

#[Group(['nested' => [new stdClass(), CaseMarker::class]])]
final class ArgumentLookAlike extends TestCase
{
    #[Group('method'), Breaks]
    public string $property = 'not a method attribute';

    public function legacy(): void
    {
        Db::transactionCommit();
    }
}

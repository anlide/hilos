<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
use PHPUnit\Framework\TestCase;

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

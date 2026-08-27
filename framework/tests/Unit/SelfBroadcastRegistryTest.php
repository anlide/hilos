<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SelfBroadcastRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SelfBroadcastRegistry}.
 */
final class SelfBroadcastRegistryTest extends TestCase
{
    public function testRegisterThenConsumeOnce(): void
    {
        $registry = new SelfBroadcastRegistry();
        $registry->register('users', '5');

        $this->assertTrue($registry->consume('users', '5'));
        $this->assertFalse($registry->consume('users', '5'));
    }

    /**
     * Looking must not spend the note. The row-sync verdict asks this before it knows
     * whose fact arrived: a foreign write to a row we are still awaiting an echo for is
     * worth a warning, and taking the note there would let our own echo through.
     */
    public function testHasLeavesTheRegistrationForTheLaterConsume(): void
    {
        $registry = new SelfBroadcastRegistry();
        $registry->register('users', '5');

        $this->assertTrue($registry->has('users', '5'));
        $this->assertTrue($registry->has('users', '5'));
        $this->assertTrue($registry->consume('users', '5'));
        $this->assertFalse($registry->has('users', '5'));
    }

    public function testHasUnknownIsFalse(): void
    {
        $registry = new SelfBroadcastRegistry();

        $this->assertFalse($registry->has('users', '5'));
    }

    public function testConsumeUnknownIsFalse(): void
    {
        $registry = new SelfBroadcastRegistry();

        $this->assertFalse($registry->consume('users', '5'));
    }

    public function testEvictsOldestWhenCapExceeded(): void
    {
        $registry = new SelfBroadcastRegistry(maxEntries: 2);
        $registry->register('c', '1');
        $registry->register('c', '2');
        $registry->register('c', '3');

        $this->assertFalse($registry->consume('c', '1'));
        $this->assertTrue($registry->consume('c', '2'));
        $this->assertTrue($registry->consume('c', '3'));
    }

    public function testReRegisterRefreshesRecency(): void
    {
        $registry = new SelfBroadcastRegistry(maxEntries: 2);
        $registry->register('c', '1');
        $registry->register('c', '2');
        $registry->register('c', '1');
        $registry->register('c', '3');

        $this->assertTrue($registry->consume('c', '1'));
        $this->assertFalse($registry->consume('c', '2'));
        $this->assertTrue($registry->consume('c', '3'));
    }
}

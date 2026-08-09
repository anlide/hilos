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

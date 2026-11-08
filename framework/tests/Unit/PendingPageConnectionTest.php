<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\PendingPageConnection;
use PHPUnit\Framework\TestCase;

/** The active-page heap supports arbitrary removals without leaving stale heads to scan. */
final class PendingPageConnectionTest extends TestCase
{
    public function testArbitraryUpdatesAndRemovalsKeepTheExactLastActiveAdmission(): void
    {
        $connection = new PendingPageConnection();
        self::assertNull($connection->lastIdentity());
        $expected = [];
        for ($index = 0; $index < 128; $index++) {
            $expected['page-' . $index] = 128 - $index;
            $connection->setPageTail('page-' . $index, 128 - $index);
        }
        self::assertSame(128, $connection->lastIdentity());
        for ($index = 0; $index < 128; $index++) {
            $page = 'page-' . (($index * 37) % 128);
            $connection->setPageTail($page, 1000 + $index);
            $expected[$page] = 1000 + $index;
            self::assertSame(max($expected), $connection->lastIdentity());
        }
        for ($index = 0; $index < 128; $index++) {
            $page = 'page-' . (($index * 53) % 128);
            $connection->setPageTail($page, null);
            unset($expected[$page]);
            self::assertSame($expected === [] ? null : max($expected), $connection->lastIdentity());
        }
        $connection->setPageTail('absent', null);
        self::assertNull($connection->lastIdentity());
    }
}

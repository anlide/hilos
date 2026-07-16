<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\DaemonContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the daemon path context.
 */
final class DaemonContextTest extends TestCase
{
    public function testExposesBootstrapDirAndProjectRoot(): void
    {
        $context = new DaemonContext('/srv/app/backend/Bootstrap', '/srv/app');

        $this->assertSame('/srv/app/backend/Bootstrap', $context->bootstrapDir);
        $this->assertSame('/srv/app', $context->projectRoot);
    }

    public function testWorkerScriptResolvesUnderBootstrapDir(): void
    {
        $context = new DaemonContext('/srv/app/backend/Bootstrap', '/srv/app');

        $this->assertSame('/srv/app/backend/Bootstrap/worker.php', $context->workerScript());
    }
}

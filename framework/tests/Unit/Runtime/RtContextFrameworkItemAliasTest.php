<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\View\Actions\Item\BackupRuntimeActions;
use Hilos\Runtime\View\Actions\Item\ProtectedModeRuntimeActions;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework-declared view aliases of the two runtime singletons.
 *
 * The framework declares how its own rows are seen while the project decides whether the
 * row is mounted at all, so the alias has to survive both cases: with a row it hands out
 * the framework's view item, and without one it answers null. That null is the contract
 * every optional-subsystem caller reads against, and losing it would turn a project that
 * simply does not use backups into an exception at the first guard.
 */
final class RtContextFrameworkItemAliasTest extends TestCase
{
    public function testMountedRowIsSeenThroughTheFrameworkViewItem(): void
    {
        $context = new FrameworkRuntimeSingletonTestRtContext();
        $context->configure();

        $this->assertInstanceOf(BackupRuntime::class, $context->hilosBackupRuntime);
    }

    public function testUnmountedRowReadsAsNullRatherThanThrowing(): void
    {
        $context = new EmptyTestRtContext();
        $context->configure();

        $this->assertNull($context->hilosBackupRuntime);
        $this->assertNull($context->hilosProtectedModeRuntime);
    }

    public function testFrameworkMountedProtectedModeIsSeenThroughItsViewItem(): void
    {
        $context = new EmptyTestRtContext();
        $context->configure();
        $context->mountFrameworkState();

        $this->assertInstanceOf(ProtectedModeRuntime::class, $context->hilosProtectedModeRuntime);
    }

    public function testTheAliasHandsOutTheActionsOfTheBackupSingleton(): void
    {
        // Standalone singletons have no parent collection to inherit an actions class from,
        // so the alias registration is the only thing that can supply one. Wiring it by hand
        // in the other tests would hide a dropped itemActionsClass here: the suite would stay
        // green while the first ->actions call in production threw.
        $context = new FrameworkRuntimeSingletonTestRtContext();
        $context->configure();

        $view = $context->hilosBackupRuntime;

        $this->assertNotNull($view);
        $this->assertNull($view->getCollection());
        $this->assertSame(StateBackupRuntime::ID, $view->getId());
        $this->assertInstanceOf(BackupRuntimeActions::class, $view->actions);
    }

    public function testTheAliasHandsOutTheActionsOfTheProtectedModeSingleton(): void
    {
        $context = new EmptyTestRtContext();
        $context->configure();
        $context->mountFrameworkState();

        $view = $context->hilosProtectedModeRuntime;

        $this->assertNotNull($view);
        $this->assertInstanceOf(ProtectedModeRuntimeActions::class, $view->actions);
    }
}

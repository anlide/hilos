<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework-owned mount of the protected mode runtime singleton.
 *
 * The row is not an opt-in project feature: a node that runs a destructive operation
 * must be able to freeze itself, so the framework mounts the item into every project
 * runtime context instead of asking each project to remember one more registration
 * line. The mount happens after the project's own configure(), so a project can
 * neither forget the row nor shadow it.
 */
final class ProtectedModeRuntimeMountTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$env = null;
        Hilos::$setting = null;
        Hilos::$db = null;
        Hilos::$notify = null;
        Hilos::$mail = null;
        Hilos::$sms = null;
        Hilos::$rt = null;
        Hilos::$table = null;
        Hilos::$fs = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testProjectThatMountsNothingStillGetsTheProtectedModeRuntime(): void
    {
        ProtectedModeMountTestHilos::init();

        $view = Hilos::$rt?->hilosProtectedModeRuntime;

        $this->assertInstanceOf(ProtectedModeRuntime::class, $view);
        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $view->phase);
    }

    public function testProjectWithoutARuntimeContextGetsNoProtectedMode(): void
    {
        // The documented limit of the guarantee: with no context there is nowhere to
        // mount the row, so such a project must introduce an RtContext before it
        // introduces a destructive operation.
        ProtectedModeMountTestNoRuntimeHilos::init();

        $this->assertNull(Hilos::$rt);
    }
}

final class ProtectedModeMountTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount must not depend on it.
     */
    public function configure(): void
    {
    }
}

final class ProtectedModeMountTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for mount tests.
     */
    public function configure(): void
    {
    }
}

class ProtectedModeMountTestHilos extends HilosFacade
{
    /**
     * @return DbContext No-op test DB context
     */
    protected static function createDb(): DbContext
    {
        return new ProtectedModeMountTestDbContext();
    }

    /**
     * @return ?RtContext Project context that registers nothing of its own
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ProtectedModeMountTestRtContext();
    }
}

final class ProtectedModeMountTestNoRuntimeHilos extends ProtectedModeMountTestHilos
{
    /**
     * @return ?RtContext Always null: this project runs without runtime state
     */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }
}

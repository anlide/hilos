<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fixtures;

use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Runtime context of a node whose admin view mode a test turns on or off.
 *
 * Carries the framework's feature rows and nothing of a project, so the runtime row of the mode is
 * the one a test reads. The caller keeps the previous {@see Hilos::$rt} and puts it back after
 * {@see self::unmount()}.
 */
final class AdminViewModeTestNode extends RtContext
{
    /**
     * Mounts no project collections: the feature rows are all the fixture carries.
     */
    public function configure(): void
    {
    }

    /**
     * Mounts the node as {@see Hilos::$rt} and switches its mode the way the master does.
     *
     * The switch syncs its row, and the frames of that sync go to a router of their own, so a test
     * reading the queue of {@see Hilos::$sr} finds only what the code under test queued.
     *
     * @param bool $enabled Whether the mode of the node is on
     */
    public static function mount(bool $enabled): void
    {
        $node = new self();
        $node->mountFeatureRuntime([]);
        Hilos::$rt = $node;
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);

        $router = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $node->hilosAdminViewModeRuntime?->actions->set($enabled);
        Hilos::$sr = $router;
    }

    /**
     * Gives up the daemon's claim on the row of the mode that {@see self::mount()} took.
     */
    public static function unmount(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
    }
}

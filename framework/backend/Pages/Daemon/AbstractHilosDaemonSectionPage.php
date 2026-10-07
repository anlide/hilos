<?php

declare(strict_types=1);

namespace Hilos\Pages\Daemon;

use Hilos\Core\Page\AbstractHilosPage;
use Hilos\Core\Page\PageRouteParams;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;

/** Shared viewer hooks for every page of the Daemon section. */
abstract class AbstractHilosDaemonSectionPage extends AbstractHilosPage
{
    /** Adds a viewer only after the page response was sent successfully. */
    protected function onSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        ClusterDaemonPictureMirror::addViewer($acceptKey);
    }

    /** Drops a viewer when it leaves this section. */
    public function onUnsubscribe(string $acceptKey): void
    {
        ClusterDaemonPictureMirror::removeViewer($acceptKey);
    }
}

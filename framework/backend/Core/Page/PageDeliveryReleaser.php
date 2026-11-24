<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Core\Execution\Exception\FramePopOrderException;

/** Lets a browser publisher release what a page held when its full answer is owed again. */
interface PageDeliveryReleaser
{
    /**
     * Runs the serving page's cleanup and keeps the resource binding for recovery.
     *
     * @param string $page Page whose publication failed
     * @param string $acceptKey Connection that was shown the error
     * @throws FramePopOrderException When the page leaves the execution stack imbalanced
     */
    public function releaseFailedPageDelivery(string $page, string $acceptKey): void;
}

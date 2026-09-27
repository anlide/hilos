<?php

declare(strict_types=1);

namespace Hilos\Files\Image;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/** Daemon proxy for the image renderer, registered beside ImagesAgent by a project declaring IMAGES. */
final class ImagesAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_IMAGES;

    /**
     * A render takes hundreds of milliseconds and hundreds of megabytes; it owns its worker's tick.
     *
     * @return bool True: rendering never shares a process with another agent
     */
    public function requiresMonopolisticProcess(): bool
    {
        return true;
    }
}

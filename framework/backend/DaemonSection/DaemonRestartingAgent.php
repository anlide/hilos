<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidFormatException;

/** One restart still open on a node's master. */
final readonly class DaemonRestartingAgent
{
    /**
     * @param string $id Agent id
     * @param string $nodeId Node hosting the old instance when restart began
     * @throws InvalidFormatException When either identity is empty
     */
    public function __construct(public string $id, public string $nodeId)
    {
        if ($id === '' || $nodeId === '') {
            throw new InvalidFormatException('Daemon restarting agent carries an empty id or node id');
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\Socket\Client\ClientInterface;

/**
 * WorkerClientInterface - Interface for worker client implementations
 *
 * Extends ClientInterface with worker-specific methods.
 * For now, it's a marker interface but can be extended with worker-specific methods.
 */
interface WorkerClientInterface extends ClientInterface
{
}


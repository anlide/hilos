<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\Socket\Client\ClientInterface;

/**
 * Interface for worker client implementations.
 *
 * Marker interface extending ClientInterface. Can be extended with worker-specific
 * methods in the future.
 */
interface WorkerClientInterface extends ClientInterface
{
}

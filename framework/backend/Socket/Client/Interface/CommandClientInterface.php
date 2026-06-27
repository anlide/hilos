<?php

declare(strict_types=1);

namespace Hilos\Socket\Client\Interface;

use Hilos\Socket\Client\ClientInterface;

/**
 * Interface for command-channel client implementations.
 *
 * Marker interface extending ClientInterface. Can be extended with
 * command-specific methods in the future (e.g. held-request parking in A2).
 */
interface CommandClientInterface extends ClientInterface
{
}

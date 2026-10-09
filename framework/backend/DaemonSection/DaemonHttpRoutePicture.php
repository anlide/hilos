<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Core\Exception\InvalidFormatException;

/** One registered HTTP method and path in a node's route picture. */
final readonly class DaemonHttpRoutePicture
{
    /**
     * @param string $method Non-empty registered method
     * @param string $path Non-empty registered path
     * @param ?string $agentType Agent answering the route, null for a master handler
     * @param HttpRouteTally $tally Current 24-hour tally
     * @throws InvalidFormatException When an identity is empty
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $agentType,
        public HttpRouteTally $tally,
    ) {
        if ($method === '' || $path === '' || $agentType === '') {
            throw new InvalidFormatException('Daemon HTTP route carries an empty identity');
        }
    }
}

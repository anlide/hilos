<?php

declare(strict_types=1);

namespace Hilos\Core\Browser;

use Hilos\Core\Router\SignalDataInterface;

/**
 * Framework subscription event captured by the browser context.
 */
final readonly class BrowserSubscriptionEvent
{
    /**
     * @param string $event BrowserContext event name
     * @param SignalDataInterface $signalData Original framework signal DTO
     * @param string $source Signal source identifier
     * @param string $name Signal name
     */
    public function __construct(
        public string $event,
        public SignalDataInterface $signalData,
        public string $source,
        public string $name,
    ) {
    }
}

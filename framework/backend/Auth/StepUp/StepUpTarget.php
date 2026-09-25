<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

/**
 * The method selected for step-up and its optional delivery destination (HIL-495).
 */
final readonly class StepUpTarget
{
    /**
     * @param string $method Step-up method (see StepUpMethod)
     * @param ?string $destination Full email address or phone number for a delivered code
     */
    public function __construct(
        public string $method,
        public ?string $destination = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Guardian\Capabilities;

use Hilos\Guardian\Contracts\GuardianCapabilityInterface;
use Hilos\Guardian\DTO\CapabilityResult;

abstract class AbstractGuardianCapability implements GuardianCapabilityInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    abstract public function execute(array $payload = [], array $context = []): CapabilityResult;
}

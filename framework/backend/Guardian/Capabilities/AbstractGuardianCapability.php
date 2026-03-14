<?php

declare(strict_types=1);

namespace Hilos\Guardian\Capabilities;

use Hilos\Guardian\Contracts\GuardianCapabilityInterface;
use Hilos\Guardian\DTO\CapabilityResult;

/**
 * Abstract base for Guardian capabilities.
 *
 * Capabilities are units of work that Guardian agents execute
 * to gather data or perform actions during investigations.
 */
abstract class AbstractGuardianCapability implements GuardianCapabilityInterface
{
    /**
     * Execute capability with payload and context.
     *
     * @param array<string, mixed> $payload Capability payload
     * @param array<string, mixed> $context Execution context
     * @return CapabilityResult Result of execution
     */
    abstract public function execute(array $payload = [], array $context = []): CapabilityResult;
}

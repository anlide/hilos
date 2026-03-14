<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\CapabilityResult;

interface GuardianCapabilityInterface
{
    /**
     * Returns capability identifier.
     *
     * @return string Capability name
     */
    public function getName(): string;

    /**
     * Executes capability with payload and context.
     *
     * @param array<string, mixed> $payload Payload data
     * @param array<string, mixed> $context Execution context
     * @return CapabilityResult Execution result
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult;
}

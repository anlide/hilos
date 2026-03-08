<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\CapabilityResult;

interface GuardianCapabilityInterface
{
    public function getName(): string;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public function execute(array $payload = [], array $context = []): CapabilityResult;
}

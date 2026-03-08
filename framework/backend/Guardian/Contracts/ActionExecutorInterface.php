<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianActionRequest;

interface ActionExecutorInterface
{
    public function execute(GuardianActionRequest $request): bool;
}

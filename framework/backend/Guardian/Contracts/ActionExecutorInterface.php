<?php

declare(strict_types=1);

namespace Hilos\Guardian\Contracts;

use Hilos\Guardian\DTO\GuardianActionRequest;

/**
 * Interface for executing guardian actions (e.g. create report).
 */
interface ActionExecutorInterface
{
    /**
     * Execute action for the given request.
     *
     * @param GuardianActionRequest $request Action request DTO
     * @return bool True if action completed successfully
     */
    public function execute(GuardianActionRequest $request): bool;
}

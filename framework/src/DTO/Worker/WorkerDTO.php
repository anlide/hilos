<?php

declare(strict_types=1);

namespace Hilos\DTO\Worker;

use Hilos\DTO\BaseDTO;

/**
 * WorkerDTO - Abstract base class for worker DTOs
 *
 * Provides common functionality for worker communication DTOs.
 */
abstract class WorkerDTO extends BaseDTO
{
    // Field name constants
    public const string TYPE = 'type';

    /**
     * Get message type
     *
     * @return string Message type
     */
    abstract public function getType(): string;
}

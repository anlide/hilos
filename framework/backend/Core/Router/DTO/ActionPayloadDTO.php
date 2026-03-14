<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;

/**
 * ActionPayloadDTO - Abstract base class for action payload DTOs.
 *
 * Provides base structure for typed action payloads.
 * Child classes in demo/app level define specific action DTOs.
 *
 * Usage:
 *   // In PageFactory
 *   public function createActionPayloadDTO(string $action, array $data): ActionPayloadDTO
 *   {
 *       return match ($action) {
 *           'message' => MessageActionDTO::fromArray($data),
 *           default => new UnknownActionPayloadDTO($action, $data),
 *       };
 *   }
 */
abstract class ActionPayloadDTO extends BaseDTO
{
    /**
     * Get action name this DTO represents
     *
     * @return string Action name
     */
    abstract public function getAction(): string;
}

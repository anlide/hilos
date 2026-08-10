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
 *   // In PageClass::ACTIONS
 *   public const array ACTIONS = [
 *       'message' => MessageActionDTO::class,
 *   ];
 *
 *   // Parsed by HilosPageFactory via Hilos::getActionDtoRoutes()
 */
abstract class ActionPayloadDTO extends BaseDTO
{
    /**
     * Gets the action name this DTO represents.
     *
     * @return string Action name
     */
    abstract public function getAction(): string;
}

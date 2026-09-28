<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Analytics\SecretPayloadMask;

/**
 * ActionPayloadDTO - Abstract base class for action payload DTOs.
 *
 * Provides base structure for typed action payloads.
 * Child classes in demo/app level define specific action DTOs.
 *
 * Every concrete child declares `public const array SECRET_FIELDS` — the payload keys
 * analytics writes as {@see SecretPayloadMask::MASK}, [] when the action carries no secret.
 * This base and the abstract intermediate classes declare nothing: the topology check asks
 * `defined()`, and a constant up the chain would answer it for every action below, the one
 * that forgot included.
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
    // Name of the constant every concrete action DTO declares its secret payload keys in
    public const string META_SECRET_FIELDS = 'SECRET_FIELDS';

    /**
     * Gets the action name this DTO represents.
     *
     * @return string Action name
     */
    abstract public function getAction(): string;
}

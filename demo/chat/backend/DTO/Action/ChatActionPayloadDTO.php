<?php

declare(strict_types=1);

namespace Demo\Chat\DTO\Action;

use Hilos\DTO\Action\ActionPayloadDTO;

/**
 * ChatActionPayloadDTO - Abstract base class for chat action payloads
 *
 * All chat-specific action DTOs extend this class.
 */
abstract class ChatActionPayloadDTO extends ActionPayloadDTO
{
}

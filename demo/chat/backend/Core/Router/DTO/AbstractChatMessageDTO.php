<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;

/**
 * AbstractChatMessageDTO - Abstract base class for chat message DTOs
 *
 * Provides base functionality for chat-specific DTOs.
 * Child classes must implement toArray() and fromArray().
 */
abstract class AbstractChatMessageDTO extends BaseDTO implements ChatMessageDTOInterface
{
}

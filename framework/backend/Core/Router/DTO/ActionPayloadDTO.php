<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageSignalRouter;

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

    /**
     * Reads a payload field the action cannot run without.
     *
     * A key that is absent, or holds anything other than a string, means the
     * client did not send the field this action is defined by; the action has
     * no value to work with and refuses. An empty string is not that case and
     * passes through: a field the user left blank is real input, and the
     * handler answers it with its own validation message.
     *
     * Refusing here is safe because {@see PageSignalRouter::dispatchAction()}
     * builds the DTO inside the try that turns any action failure into the
     * client's fail-ack.
     *
     * @param array<string, mixed> $data Payload the DTO is being built from
     * @param string $key Payload key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    protected static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            // The message rides the client's fail-ack through
            // PageSignalRouter::clientReason(), so it names the payload key and
            // nothing about the class that reads it.
            throw new InvalidFormatException('Action payload carries no string under key ' . $key);
        }

        return $value;
    }
}

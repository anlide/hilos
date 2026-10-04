<?php

declare(strict_types=1);

namespace Hilos\Core\Router\DTO;

/**
 * A reply already in its wire form, sent on by an agent that did not produce it.
 *
 * Some actions are answered from behind another agent: the sessions library answers the
 * actions it deferred from the agent that holds the socket, so the answer leaves behind the
 * session frame it belongs to. The reply reaches that agent as the array its producer
 * serialized - a sign-in outcome, or the code-send reply of a profile window (HIL-1186) - and
 * the holder only passes it on. Rebuilding a concrete reply class there would tie the relay to
 * one kind of answer and refuse every other one; the receiver reads the flat array anyway.
 */
final class RelayedActionReplyDTO extends ActionReplyDTO
{
    /**
     * @param array<string, mixed> $reply Wire form of the reply, as its producer serialized it
     */
    public function __construct(
        public readonly array $reply,
    ) {
    }

    /**
     * @return array<string, mixed> The reply, unchanged
     */
    public function toArray(): array
    {
        return $this->reply;
    }

    /**
     * @param array<string, mixed> $data Wire form of a reply
     * @return static The reply, carried as it is
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}

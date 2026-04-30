<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\AttachmentDraft;
use Hilos\Runtime\State\Collection\RtStates;
use OutOfBoundsException;

/**
 * AttachmentDrafts - uploaded quarantine files waiting for message submit.
 *
 * @extends RtStates<AttachmentDraft>
 */
final class AttachmentDrafts extends RtStates
{
    public const string STATE_CLASS = AttachmentDraft::class;

    /**
     * @param string $id Draft id
     * @return ?AttachmentDraft Draft runtime state, or null when missing
     */
    public function get(string $id): ?AttachmentDraft
    {
        /** @var ?AttachmentDraft $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use {@see get()} when absence is valid.
     *
     * @param mixed $offset Draft id
     * @return AttachmentDraft Draft runtime state
     */
    public function offsetGet(mixed $offset): AttachmentDraft
    {
        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Attachment draft runtime state not found: {$offset}");
    }

    /**
     * Finds all drafts owned by a WebSocket connection.
     *
     * @param string $acceptKey WebSocket connection id
     * @return array<string, AttachmentDraft> Draft id => state map
     */
    public function findAllByAcceptKey(string $acceptKey): array
    {
        return array_filter($this->states, static function ($draft) use ($acceptKey) {
            return $draft instanceof AttachmentDraft && $draft->acceptKey === $acceptKey;
        });
    }
}

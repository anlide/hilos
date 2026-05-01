<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\View\Actions\Item\AttachmentDraftActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only item for an uploaded attachment draft.
 *
 * @extends RtItem<StateAttachmentDraft>
 *
 * @property-read string $draftId Client-visible draft id
 * @property-read string $acceptKey Owning WebSocket connection id
 * @property-read int $userId Owning user id
 * @property-read string $quarantineBasename Quarantine filename
 * @property-read string $originalFilename Original client filename
 * @property-read string $mimeType Client-declared MIME type
 * @property-read int $size File size in bytes
 * @property-read string $normalizedFilename Normalized filename
 * @property-read int $uploadedAt Upload completion unix timestamp
 * @property-read AttachmentDraftActions $actions Write operations for this draft
 */
final class AttachmentDraft extends RtItem
{
    /**
     * @param StateAttachmentDraft $state Backing state
     */
    public function __construct(StateAttachmentDraft &$state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates known keys to the backing state.
     *
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|int|AttachmentDraftActions
    {
        /** @var StateAttachmentDraft $state */
        $state = $this->_state;

        return match ($name) {
            StateAttachmentDraft::draftId => $state->draftId,
            StateAttachmentDraft::acceptKey => $state->acceptKey,
            StateAttachmentDraft::userId => $state->userId,
            StateAttachmentDraft::quarantineBasename => $state->quarantineBasename,
            StateAttachmentDraft::originalFilename => $state->originalFilename,
            StateAttachmentDraft::mimeType => $state->mimeType,
            StateAttachmentDraft::size => $state->size,
            StateAttachmentDraft::normalizedFilename => $state->normalizedFilename,
            StateAttachmentDraft::uploadedAt => $state->uploadedAt,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        /** @var StateAttachmentDraft $state */
        $state = $this->_state;

        return $state->toArray();
    }
}

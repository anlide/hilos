<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\View\Actions\Item\AttachmentDraftActions;
use Demo\Chat\Runtime\View\Context\RtChatContext;
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
 * @property-read ?User $user User row or null if not found in DB view
 * @property-read ?Connection $connection Owning connection row or null if not found
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
    public function __get(string $name): string|int|User|Connection|AttachmentDraftActions|null
    {
        return match ($name) {
            StateAttachmentDraft::draftId => $this->_state->draftId,
            StateAttachmentDraft::acceptKey => $this->_state->acceptKey,
            StateAttachmentDraft::userId => $this->_state->userId,
            StateAttachmentDraft::quarantineBasename => $this->_state->quarantineBasename,
            StateAttachmentDraft::originalFilename => $this->_state->originalFilename,
            StateAttachmentDraft::mimeType => $this->_state->mimeType,
            StateAttachmentDraft::size => $this->_state->size,
            StateAttachmentDraft::normalizedFilename => $this->_state->normalizedFilename,
            StateAttachmentDraft::uploadedAt => $this->_state->uploadedAt,
            DbChatContext::user => Hilos::$db->users[$this->_state->userId],
            RtChatContext::connection => Hilos::$rt->connections[$this->_state->acceptKey],
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

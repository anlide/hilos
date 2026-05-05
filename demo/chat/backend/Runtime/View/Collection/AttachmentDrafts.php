<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Collection;

use Demo\Chat\Runtime\State\Collection\AttachmentDrafts as StateAttachmentDrafts;
use Demo\Chat\Runtime\State\Item\AttachmentDraft as StateAttachmentDraft;
use Demo\Chat\Runtime\View\Actions\Collection\AttachmentDraftsActions;
use Demo\Chat\Runtime\View\Actions\Item\AttachmentDraftActions;
use Demo\Chat\Runtime\View\Item\AttachmentDraft;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;

/**
 * AttachmentDrafts - read-only wrapper around uploaded attachment drafts.
 *
 * @extends RtCollection<AttachmentDraft, AttachmentDraftsActions>
 * @property-read AttachmentDraftsActions $actions Actions for write operations
 */
final class AttachmentDrafts extends RtCollection
{
    /**
     * @throws RtActionsStateCollectionNullException
     */
    public function getStateCollection(): StateAttachmentDrafts
    {
        /** @var StateAttachmentDrafts */
        return parent::getStateCollection();
    }

    /**
     * Drafts owned by one WebSocket connection.
     *
     * @param string $acceptKey WebSocket connection id
     * @return self Filtered collection
     * @throws RtActionsStateCollectionNullException When runtime state is unavailable
     */
    public function forAcceptKey(string $acceptKey): self
    {
        $filteredState = StateAttachmentDrafts::init();
        foreach ($this->getStateCollection()->findAllByAcceptKey($acceptKey) as $stateDraft) {
            $filteredState->add($stateDraft);
        }

        return $this->fromFilteredState($filteredState);
    }

    private function fromFilteredState(StateAttachmentDrafts $filteredState): self
    {
        $collection = self::init();
        $collection->setStateCollection($filteredState);
        $collectionName = $this->getCollectionName();
        if ($collectionName !== null) {
            $collection->setCollectionName($collectionName);
        }
        $collection->setActionsClass(AttachmentDraftsActions::class);
        $collection->setItemActionsClass(AttachmentDraftActions::class);

        return $collection;
    }

    /**
     * Sum file sizes for all active drafts.
     */
    public function sumDraftBytes(): int
    {
        $sum = 0;
        foreach ($this as $draft) {
            $sum += $draft->size;
        }

        return $sum;
    }

    /**
     * Whether a draft already uses this normalized filename.
     *
     * @param string $normalized Normalized basename
     */
    public function hasDraftWithNormalizedFilename(string $normalized): bool
    {
        foreach ($this as $draft) {
            if ($draft->normalizedFilename === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param RtState $state StateAttachmentDraft instance
     * @return AttachmentDraft View item for this draft
     */
    protected function createRtItem(RtState &$state): AttachmentDraft
    {
        /** @var StateAttachmentDraft $state */
        return new AttachmentDraft($state);
    }

    /**
     * @param mixed $offset Draft id
     * @return ?AttachmentDraft Item or null
     */
    public function offsetGet(mixed $offset): ?AttachmentDraft
    {
        /** @var ?AttachmentDraft $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return ?AttachmentDraft Current item or null
     */
    public function current(): ?AttachmentDraft
    {
        /** @var ?AttachmentDraft $item */
        $item = parent::current();

        return $item;
    }

    /**
     * @param string $key Draft id
     * @return ?AttachmentDraft Item or null
     */
    protected function getRtItemForKey(string $key): ?AttachmentDraft
    {
        /** @var ?AttachmentDraft $item */
        $item = parent::getRtItemForKey($key);

        return $item;
    }

    /**
     * @return AttachmentDraftsActions Actions instance
     * @throws RtCollectionActionsClassException If actions class mismatch
     */
    protected function getActions(): AttachmentDraftsActions
    {
        /** @var AttachmentDraftsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @throws RtCollectionPropertyNotFoundException
     * @throws RtCollectionActionsClassException
     */
    public function __get(string $name): AttachmentDraftsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

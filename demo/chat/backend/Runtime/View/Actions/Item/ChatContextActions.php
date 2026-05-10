<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Item;

use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\DTO\ChatContextUpdateData;
use Demo\Chat\Runtime\View\Item\ChatContext as RuntimeChatContext;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for the main chat context runtime item.
 *
 * @extends RtActions<RuntimeChatContext, StateChatContext>
 * @property-read StateChatContext $state
 */
final class ChatContextActions extends RtActions
{
    /**
     * Update the main chat context fields.
     *
     * @param ChatContextUpdateData $data Fields to set
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function update(ChatContextUpdateData $data): void
    {
        $this->ensureCanWrite();

        $this->state->topic = $data->topic !== null && $data->topic !== '' ? $data->topic : null;
        $this->state->topicConfidence = $data->topicConfidence;
        $this->state->summary = $data->summary;

        $this->sync();
    }
}

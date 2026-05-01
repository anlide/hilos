<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\State\Collection\ChatContexts as StateChatContexts;
use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Collection\ChatContexts;
use Demo\Chat\Runtime\View\Item\ChatContext as RuntimeChatContext;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;

/**
 * ChatContextsActions - write operations for chat context.
 *
 * Usage:
 *   Hilos::$rt->chatContexts->actions->init();  // Creates empty context
 *   Hilos::$rt->chatContexts[ChatContext::ID_MAIN]->actions->update($data);
 *
 * @extends RtActions<RuntimeChatContext, ChatContexts, StateChatContexts>
 * @property-read StateChatContexts $stateCollection
 */
final class ChatContextsActions extends RtActions
{
    /**
     * Narrows parent return type to this collection's RtItem ({@see RuntimeChatContext}).
     * @throws RtActionsCallbackNotSetException
     */
    protected function createRtItemFromState(RtState &$state): RuntimeChatContext
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof RuntimeChatContext) {
            throw new \LogicException('ChatContexts item factory must return ' . RuntimeChatContext::class);
        }

        return $item;
    }

    /**
     * Initialize the main chat context (creates empty).
     *
     * @return RuntimeChatContext Created context
     * @throws RtActionsCallbackNotSetException When callback for creating RT item from state is not set (should be set in constructor of parent class)
     * @throws RtActionsCollectionNameNullException When collection name is null.
     * @throws RtTruthSourceWriteNotAllowedException When truth source does not allow write.
     * @throws RtActionsStateCollectionNullException
     */
    public function init(): RuntimeChatContext
    {
        $state = StateChatContext::create();
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

}

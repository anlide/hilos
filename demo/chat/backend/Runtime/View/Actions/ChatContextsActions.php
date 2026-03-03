<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions;

use Demo\Chat\Runtime\State\Item\ChatContext as StateChatContext;
use Demo\Chat\Runtime\View\Item\ChatContext as RuntimeChatContext;
use Hilos\Runtime\View\Actions\RtActions;

/**
 * ChatContextsActions - write operations for chat context.
 *
 * Usage:
 *   Hilos::$rt->chatContexts->actions->init();  // Creates empty context
 *   Hilos::$rt->chatContexts->actions->update($data);  // Updates existing
 */
class ChatContextsActions extends RtActions
{
    /**
     * Initialize the main chat context (creates empty).
     *
     * @return RuntimeChatContext Created context
     */
    public function init(): RuntimeChatContext
    {
        $state = StateChatContext::create();
        $this->addStateToCollection($state);
        return $this->createRtItemFromState($state);
    }

    /**
     * Update the main chat context.
     *
     * @param array<string, mixed> $data Fields to set (topic, summary, topicConfidence)
     * @return RuntimeChatContext Updated context
     */
    public function update(array $data): RuntimeChatContext
    {
        $this->ensureCanWrite();
        $stateCollection = $this->getStateCollection();
        $existing = $stateCollection->get(StateChatContext::ID_MAIN);
        if ($existing === null) {
            throw new \RuntimeException('ChatContext not initialized. Call init() first.');
        }

        $this->applyDiffToState($existing, $data);
        return $this->createRtItemFromState($existing);
    }
}

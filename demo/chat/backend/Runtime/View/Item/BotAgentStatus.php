<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Demo\Chat\Runtime\View\Actions\Item\BotAgentStatusActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only wrapper over a bot agent lifecycle runtime row.
 *
 * @extends RtItem<StateBotAgentStatus>
 *
 * @property-read int $botId Bot database id
 * @property-read string $status Lifecycle marker
 * @property-read int $updatedAt Last update unix time
 * @property-read BotAgentStatusActions $actions Write operations for this bot status
 */
final class BotAgentStatus extends RtItem
{
    /**
     * @param StateBotAgentStatus $state Backing runtime state
     */
    public function __construct(StateBotAgentStatus &$state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): int|string|BotAgentStatusActions
    {
        /** @var StateBotAgentStatus $state */
        $state = $this->_state;

        return match ($name) {
            StateBotAgentStatus::botId => $state->botId,
            StateBotAgentStatus::status => $state->status,
            StateBotAgentStatus::updatedAt => $state->updatedAt,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        /** @var StateBotAgentStatus $state */
        $state = $this->_state;

        return $state->toArray();
    }
}

<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\Bot;
use Demo\Chat\Hilos;
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
 * @property-read ?Bot $bot Bot row or null if not found in DB view
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
    public function __get(string $name): int|string|Bot|BotAgentStatusActions|null
    {
        return match ($name) {
            StateBotAgentStatus::botId => $this->_state->botId,
            StateBotAgentStatus::status => $this->_state->status,
            StateBotAgentStatus::updatedAt => $this->_state->updatedAt,
            DbChatContext::bot => Hilos::$db->bots[$this->_state->botId],
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

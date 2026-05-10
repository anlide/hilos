<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\BotActions;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Item\BotAgentStatus as RuntimeBotAgentStatus;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * Bot - Db item with high-level abstraction and lazy loading.
 *
 * @extends DbItem<ObjectBot>
 * @method __construct(ObjectBot &$objectBot)
 *
 * @property-read ?int $id
 * @property-read string $name
 * @property-read ?string $description
 * @property-read ?string $style
 * @property-read ?string $topics
 * @property-read ?string $personality
 * @property-read bool $active
 * @property-read int $reactionDelayMin
 * @property-read int $reactionDelayMax
 * @property-read int $reactionChance
 * @property-read bool $topicMatchRequired
 * @property-read int $cooldownAfterMessage
 * @property-read int $priority
 * @property-read ?RuntimeBotAgentStatus $agentStatus Runtime lifecycle row for this bot
 * @property-read BotActions $actions Item-level write operations
 */
final class Bot extends DbItem
{
    public const string agentStatus = 'agentStatus';

    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|bool|RuntimeBotAgentStatus|BotActions|null Property value, linked runtime status, or actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If actions class is not defined for the collection
     */
    public function __get(string $name): int|string|bool|RuntimeBotAgentStatus|BotActions|null
    {
        return match ($name) {
            ObjectBot::id => $this->_object->id,
            ObjectBot::name => $this->_object->name,
            ObjectBot::description => $this->_object->description,
            ObjectBot::style => $this->_object->style,
            ObjectBot::topics => $this->_object->topics,
            ObjectBot::personality => $this->_object->personality,
            ObjectBot::active => $this->_object->active,
            ObjectBot::reactionDelayMin => $this->_object->reactionDelayMin,
            ObjectBot::reactionDelayMax => $this->_object->reactionDelayMax,
            ObjectBot::reactionChance => $this->_object->reactionChance,
            ObjectBot::topicMatchRequired => $this->_object->topicMatchRequired,
            ObjectBot::cooldownAfterMessage => $this->_object->cooldownAfterMessage,
            ObjectBot::priority => $this->_object->priority,
            self::agentStatus => Hilos::$rt->botAgentStatuses[$this->_object->id],
            default => parent::__get($name),
        };
    }
}

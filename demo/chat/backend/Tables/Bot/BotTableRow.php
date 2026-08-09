<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Runtime\State\Item\BotAgentStatus as StateBotAgentStatus;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload for the bots table.
 */
final class BotTableRow extends AbstractTableRow
{
    public const string id = ObjectBot::id;
    public const string name = ObjectBot::name;
    public const string description = ObjectBot::description;
    public const string style = ObjectBot::style;
    public const string topics = ObjectBot::topics;
    public const string personality = ObjectBot::personality;
    public const string active = ObjectBot::active;
    public const string reactionDelayMin = ObjectBot::reactionDelayMin;
    public const string reactionDelayMax = ObjectBot::reactionDelayMax;
    public const string reactionChance = ObjectBot::reactionChance;
    public const string topicMatchRequired = ObjectBot::topicMatchRequired;
    public const string cooldownAfterMessage = ObjectBot::cooldownAfterMessage;
    public const string priority = ObjectBot::priority;
    /** Runtime agent lifecycle status (joined/left), merged from RT — not a DB column. */
    public const string status = StateBotAgentStatus::status;

    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public ?string $style,
        public ?string $topics,
        public ?string $personality,
        public bool $active,
        public int $reactionDelayMin,
        public int $reactionDelayMax,
        public int $reactionChance,
        public bool $topicMatchRequired,
        public int $cooldownAfterMessage,
        public int $priority,
        public ?string $status = null,
    ) {
    }

    /**
     * Returns the stable row key used by the bots table.
     */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /**
     * Serializes the row to the bots table payload shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
            self::description => $this->description,
            self::style => $this->style,
            self::topics => $this->topics,
            self::personality => $this->personality,
            self::active => $this->active,
            self::reactionDelayMin => $this->reactionDelayMin,
            self::reactionDelayMax => $this->reactionDelayMax,
            self::reactionChance => $this->reactionChance,
            self::topicMatchRequired => $this->topicMatchRequired,
            self::cooldownAfterMessage => $this->cooldownAfterMessage,
            self::priority => $this->priority,
            self::status => $this->status,
        ];
    }

    /**
     * Builds a bot table row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: (int) ($data[self::id] ?? 0),
            name: (string) $data[self::name],
            description: isset($data[self::description]) ? (string) $data[self::description] : null,
            style: isset($data[self::style]) ? (string) $data[self::style] : null,
            topics: isset($data[self::topics]) ? (string) $data[self::topics] : null,
            personality: isset($data[self::personality]) ? (string) $data[self::personality] : null,
            active: (bool) ($data[self::active] ?? false),
            reactionDelayMin: (int) ($data[self::reactionDelayMin] ?? 0),
            reactionDelayMax: (int) ($data[self::reactionDelayMax] ?? 0),
            reactionChance: (int) ($data[self::reactionChance] ?? 0),
            topicMatchRequired: (bool) ($data[self::topicMatchRequired] ?? false),
            cooldownAfterMessage: (int) ($data[self::cooldownAfterMessage] ?? 0),
            priority: (int) ($data[self::priority] ?? 0),
            status: isset($data[self::status]) ? (string) $data[self::status] : null,
        );
    }
}

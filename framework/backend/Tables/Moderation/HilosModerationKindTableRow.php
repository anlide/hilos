<?php

declare(strict_types=1);

namespace Hilos\Tables\Moderation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One published moderation kind and its current model and own-rule count. */
final class HilosModerationKindTableRow extends AbstractTableRow
{
    public const string kind = 'kind';
    public const string title = 'title';
    public const string owner = 'owner';
    public const string input = 'input';
    public const string model = 'model';
    public const string ownRuleCount = 'ownRuleCount';

    /**
     * @param string $kind Published kind key and row key
     * @param string $title Directory title
     * @param string $owner Directory owner: framework or project
     * @param string $input Declared input: text, image or text_with_files
     * @param string $model Current model setting for the input
     * @param int $ownRuleCount Number of persisted rules belonging to this kind
     * @throws InvalidFormatException When the own-rule count is negative
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $owner,
        public string $input,
        public string $model,
        public int $ownRuleCount,
    ) {
        if ($ownRuleCount < 0) {
            throw new InvalidFormatException('A moderation kind cannot have a negative rule count');
        }
    }

    /** @return string Published kind key */
    public function getRowKey(): string
    {
        return $this->kind;
    }

    /** @return string Payload field carrying the kind key */
    public static function keyField(): string
    {
        return self::kind;
    }

    /** @return array<string, mixed> Kind row payload */
    public function toArray(): array
    {
        return [
            self::kind => $this->kind,
            self::title => $this->title,
            self::owner => $this->owner,
            self::input => $this->input,
            self::model => $this->model,
            self::ownRuleCount => $this->ownRuleCount,
        ];
    }

    /**
     * @param array<string, mixed> $data Complete kind row payload
     * @return static Reconstructed kind row
     * @throws InvalidFormatException When a field is missing, mistyped or carries a negative count
     */
    public static function fromArray(array $data): static
    {
        return new static(
            kind: self::requireString($data, self::kind),
            title: self::requireString($data, self::title),
            owner: self::requireString($data, self::owner),
            input: self::requireString($data, self::input),
            model: self::requireString($data, self::model),
            ownRuleCount: self::requireInt($data, self::ownRuleCount),
        );
    }
}

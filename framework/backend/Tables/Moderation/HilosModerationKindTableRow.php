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
    public const string profile = 'profile';
    public const string reasons = 'reasons';

    /**
     * @param string $kind Published kind key and row key
     * @param string $title Directory title
     * @param string $owner Directory owner: framework or project
     * @param string $input Declared input: text, image or text_with_files
     * @param string $model Current model setting for the input
     * @param int $ownRuleCount Number of persisted rules belonging to this kind
     * @param string $profile LLM profile selected by the declared input
     * @param list<string> $reasons Declared refusal reasons in directory order
     * @throws InvalidFormatException When the count is negative or reasons are not a list of strings
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $owner,
        public string $input,
        public string $model,
        public int $ownRuleCount,
        public string $profile,
        public array $reasons,
    ) {
        if ($ownRuleCount < 0) {
            throw new InvalidFormatException('A moderation kind cannot have a negative rule count');
        }
        if (!array_is_list($reasons)) {
            throw new InvalidFormatException('Moderation kind reasons must be a list of strings');
        }
        foreach ($reasons as $reason) {
            if (!is_string($reason)) {
                throw new InvalidFormatException('Moderation kind reasons must be strings');
            }
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
            self::profile => $this->profile,
            self::reasons => $this->reasons,
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
            profile: self::requireString($data, self::profile),
            reasons: self::requireArray($data, self::reasons),
        );
    }
}

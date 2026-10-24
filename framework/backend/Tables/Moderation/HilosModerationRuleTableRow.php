<?php

declare(strict_types=1);

namespace Hilos\Tables\Moderation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One persisted moderation rule belonging to a published kind. */
final class HilosModerationRuleTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string kind = 'kind';
    public const string rule = 'rule';

    /**
     * @param int $id Persisted rule id and row key
     * @param string $kind Published kind key
     * @param string $rule Own rule text
     * @throws InvalidFormatException When the rule id is not positive
     */
    public function __construct(public int $id, public string $kind, public string $rule)
    {
        if ($id <= 0) {
            throw new InvalidFormatException('A moderation rule must have a positive id');
        }
    }

    /** @return int Persisted rule id */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /** @return string Payload field carrying the rule id */
    public static function keyField(): string
    {
        return self::id;
    }

    /** @return array<string, mixed> Rule row payload */
    public function toArray(): array
    {
        return [self::id => $this->id, self::kind => $this->kind, self::rule => $this->rule];
    }

    /**
     * @param array<string, mixed> $data Complete rule row payload
     * @return static Reconstructed rule row
     * @throws InvalidFormatException When a field is missing, mistyped or carries a nonpositive id
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: self::requireInt($data, self::id),
            kind: self::requireString($data, self::kind),
            rule: self::requireString($data, self::rule),
        );
    }
}

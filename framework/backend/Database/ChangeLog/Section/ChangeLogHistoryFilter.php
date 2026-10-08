<?php

declare(strict_types=1);

namespace Hilos\Database\ChangeLog\Section;

use DateTimeImmutable;
use Hilos\Core\Exception\InvalidArgumentException;

/** Optional record, field, person, and UTC-period filters of a table's history. */
final readonly class ChangeLogHistoryFilter
{
    public ?string $who;

    /**
     * @param ?list<int|string> $recordKey Live primary-key values in column order
     * @param ?string $field Updated column name
     * @param ?string $who Current name substring or numeric person id
     * @param ?DateTimeImmutable $since Inclusive UTC lower bound
     * @param ?DateTimeImmutable $until Exclusive UTC upper bound
     * @throws InvalidArgumentException When the record key is not a list of scalar key parts
     */
    public function __construct(
        public ?array $recordKey = null,
        public ?string $field = null,
        ?string $who = null,
        public ?DateTimeImmutable $since = null,
        public ?DateTimeImmutable $until = null,
    ) {
        if ($recordKey !== null && (!array_is_list($recordKey)
            || array_filter($recordKey, static fn(mixed $part): bool => !is_int($part) && !is_string($part)) !== [])) {
            throw new InvalidArgumentException('Change log record key must be a list of integer or string values');
        }
        $trimmed = $who === null ? null : trim($who);
        $this->who = $trimmed === '' ? null : $trimmed;
    }
}

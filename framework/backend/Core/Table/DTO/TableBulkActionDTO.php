<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Table\TableConstants;

/**
 * TableBulkActionDTO - what a bulk action over a table is asked to run over.
 *
 * The framework fixes the shape of the request and nothing else: the action's own name is
 * declared by the page in its ACTIONS, as it declares every other table action, so this class
 * stays abstract and leaves getAction() to the concrete payload of each operation.
 *
 * The target is EXACTLY ONE of two: the rows named one by one, or the condition that describes
 * them. Never both and never neither - "all matching the filter" is a condition rather than a
 * list of keys, because over a large set an exact list does not exist, and a request carrying
 * both would be two targets with no rule for which one wins. A request that breaks the shape is
 * refused with a reason rather than guessed at, which is why the refusal is an
 * {@see InvalidFormatException}: its message is written for the person who asked.
 *
 * The search term is not a field of its own. It lives in the filter map under
 * {@see TableConstants::FILTER_KEY_SEARCH}, the way every other viewport filter does, and the
 * table lifts it from there itself.
 */
abstract class TableBulkActionDTO extends ActionPayloadDTO
{
    /**
     * Declares one bulk action request.
     *
     * @param string $tableKey Table the operation runs over
     * @param ?list<string> $rowKeys Rows named one by one, or null when the target is a condition
     * @param ?array<string, mixed> $filter Condition describing the rows, or null when they are named
     * @throws InvalidFormatException When the request names both targets, neither of them, no table, or an empty list
     */
    public function __construct(
        public readonly string $tableKey,
        public readonly ?array $rowKeys,
        public readonly ?array $filter,
    ) {
        if ($tableKey === '') {
            throw new InvalidFormatException(
                'Payload carries no table under key ' . TableConstants::PAYLOAD_KEY_TABLE_KEY,
            );
        }
        if (($rowKeys === null) === ($filter === null)) {
            throw new InvalidFormatException(
                'A bulk action runs over rows under key ' . TableConstants::PAYLOAD_KEY_ROW_KEYS
                . ' or over a condition under key ' . SignalPayloadConstants::FIELD_FILTER
                . ', and over exactly one of the two',
            );
        }
        if ($rowKeys === []) {
            // The selection panel shows no bulk buttons while nothing is marked, so an empty
            // list is not a person choosing nothing - it is a caller that lost its target.
            throw new InvalidFormatException(
                'A bulk action over named rows names none under key ' . TableConstants::PAYLOAD_KEY_ROW_KEYS,
            );
        }
    }

    /**
     * Converts the request to its wire array.
     *
     * The target that was not chosen is absent rather than null, because the shape is "one of
     * two" and a null beside a value would read as a second, emptied target.
     *
     * @return array<string, mixed> Request payload in the bulk-action wire form
     */
    public function toArray(): array
    {
        $payload = [TableConstants::PAYLOAD_KEY_TABLE_KEY => $this->tableKey];
        if ($this->rowKeys !== null) {
            $payload[TableConstants::PAYLOAD_KEY_ROW_KEYS] = $this->rowKeys;
        }
        if ($this->filter !== null) {
            $payload[SignalPayloadConstants::FIELD_FILTER] = $this->filter;
        }

        return $payload;
    }

    /**
     * Restores the request from its wire array.
     *
     * @param array<string, mixed> $data Source data in the bulk-action wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the table is missing, the target is not exactly one, or a row key is not a string
     */
    public static function fromArray(array $data): static
    {
        $rowKeys = self::optionalArray($data, TableConstants::PAYLOAD_KEY_ROW_KEYS);

        return new static(
            tableKey: self::requireString($data, TableConstants::PAYLOAD_KEY_TABLE_KEY),
            rowKeys: $rowKeys === null ? null : self::readRowKeys($rowKeys),
            filter: self::optionalArray($data, SignalPayloadConstants::FIELD_FILTER),
        );
    }

    /**
     * Reads the named rows of a target as a list of keys.
     *
     * @param array<string, mixed> $rowKeys Raw value the payload carried under the row-keys key
     * @return list<string> Row keys in the order they arrived
     * @throws InvalidFormatException When any entry is not a string
     */
    private static function readRowKeys(array $rowKeys): array
    {
        $keys = [];
        foreach ($rowKeys as $rowKey) {
            if (!is_string($rowKey)) {
                throw new InvalidFormatException(
                    'Payload carries a non-string row key under key ' . TableConstants::PAYLOAD_KEY_ROW_KEYS,
                );
            }
            $keys[] = $rowKey;
        }

        return $keys;
    }
}

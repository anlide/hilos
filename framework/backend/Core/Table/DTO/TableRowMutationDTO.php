<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;

/**
 * DTO for one table row mutation (create/update/delete).
 *
 * Broadcast to all connected users so the frontend can show pending-change indicators.
 *
 * A mutation may declare itself {@see $live}: the row is not data the user is reading but a
 * status the table shows *about* work in progress, so freezing it behind Apply would strand a
 * progress row on screen after the work it reports has finished. Live is the exception - a row
 * carrying content stays gated, because the gate exists so a table never rearranges itself
 * under the reader's hands.
 */
readonly class TableRowMutationDTO
{
    /**
     * Creates a table row mutation DTO.
     *
     * @param TableMutationType $type Mutation type (create, update, delete)
     * @param string|int $rowKey Affected row key
     * @param ?AbstractTableRow $row Optional row data for create/update
     * @param bool $live Whether the change must apply at once instead of waiting for Apply
     */
    public function __construct(
        public TableMutationType $type,
        public string|int $rowKey,
        public ?AbstractTableRow $row = null,
        public bool $live = false,
    ) {
    }

    /**
     * Converts to array for WebSocket serialization.
     *
     * @return array<string, mixed> Payload with type, rowKey, optional row
     */
    public function toArray(): array
    {
        $data = [
            TableConstants::MUTATION_KEY_TYPE => $this->type->value,
            TableConstants::MUTATION_KEY_ROW_KEY => $this->rowKey,
        ];
        if ($this->row !== null) {
            $data[TableConstants::MUTATION_KEY_ROW] = $this->row->toArray();
        }
        return $data;
    }

    /**
     * Rebuilds a table row mutation DTO from its serialized form.
     *
     * When the concrete table row class is unknown in the current context, the
     * row is restored as {@see GenericTableRow}.
     *
     * The payload is read the way {@see BaseDTO} reads one — a field the
     * mutation has no meaning without is refused rather than minted — but the
     * checks are written here: this is a readonly value object, and PHP does not
     * let one extend the non-readonly base the helpers live on. The row itself
     * stays optional, because a delete carries none.
     *
     * @param array<string, mixed> $data Serialized mutation payload
     * @return self Reconstructed row mutation DTO
     * @throws InvalidFormatException When the payload misses the mutation type or the row key, or names a type that does not exist
     */
    public static function fromArray(array $data): self
    {
        $type = $data[TableConstants::MUTATION_KEY_TYPE] ?? null;
        if (!is_string($type)) {
            throw new InvalidFormatException('Payload carries no string under key ' . TableConstants::MUTATION_KEY_TYPE);
        }

        $rowKey = $data[TableConstants::MUTATION_KEY_ROW_KEY] ?? null;
        if (!is_int($rowKey) && !is_string($rowKey)) {
            throw new InvalidFormatException(
                'Payload carries no integer or string under key ' . TableConstants::MUTATION_KEY_ROW_KEY,
            );
        }

        $row = $data[TableConstants::MUTATION_KEY_ROW] ?? null;
        if ($row !== null && !is_array($row)) {
            throw new InvalidFormatException('Payload carries a non-array under key ' . TableConstants::MUTATION_KEY_ROW);
        }

        return new self(
            type: TableMutationType::tryFrom($type)
                ?? throw new InvalidFormatException('Payload names no known mutation type: ' . $type),
            rowKey: $rowKey,
            row: $row === null ? null : GenericTableRow::fromArray($row),
        );
    }
}

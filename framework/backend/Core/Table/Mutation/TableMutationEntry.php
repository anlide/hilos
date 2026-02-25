<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Mutation;

/**
 * Single table mutation event (create/update/delete).
 *
 * Broadcast to all connected users so the frontend can show pending-change indicators.
 */
class TableMutationEntry
{
    public function __construct(
        public readonly TableMutationType $type,
        public readonly string|int $rowId,
        public readonly ?array $row = null,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            'type' => $this->type->value,
            'rowId' => $this->rowId,
        ];
        if ($this->row !== null) {
            $data['row'] = $this->row;
        }
        return $data;
    }
}

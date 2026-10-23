<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource\Exception;

/** Refuses an application write to rows owned by an external database writer. */
class ExternalWriterException extends WriteNotAllowedException
{
    /**
     * @param string $collection Collection key or physical table name of the refused write
     */
    public function __construct(string $collection)
    {
        parent::__construct(
            "ORM write not allowed for collection/table '{$collection}': it has an external database writer."
        );
    }
}

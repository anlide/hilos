<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\I18nReflowsActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\I18nReflow as EntityI18nReflow;
use Hilos\Database\Object\Collection\I18nReflows as ObjectI18nReflows;
use Hilos\Database\View\Item\I18nReflow;

/**
 * The one-row record of the catalog reflow; its offset is the primary id, always 1.
 *
 * @extends DbCollection<I18nReflow, ObjectI18nReflows>
 * @property-read I18nReflowsActions $actions
 */
class I18nReflows extends DbCollection
{
    public const string DB_ITEM_CLASS = I18nReflow::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectI18nReflows::class;

    /**
     * @return ?string Fingerprint of the catalog last taken in, or null when none ever was
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws DatabaseException When the first read of the table fails
     */
    public function recordedFingerprint(): ?string
    {
        return $this->offsetGet(EntityI18nReflow::ROW_ID)?->fingerprint;
    }
}

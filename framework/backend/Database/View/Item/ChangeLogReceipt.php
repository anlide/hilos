<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\ChangeLogReceiptActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\ChangeLogReceipt as ObjectChangeLogReceipt;
use Hilos\HilosException;

/**
 * @extends DbItem<ObjectChangeLogReceipt>
 * @method __construct(ObjectChangeLogReceipt $object)
 *
 * @property-read ?int $id
 * @property-read string $created_at
 * @property-read ?int $actor_user_id
 * @property-read ?int $subject_user_id
 * @property-read ?int $session_id
 * @property-read string $channel
 * @property-read string $action
 * @property-read ?string $agent
 * @property-read ?string $source
 * @property-read int $open_handovers
 * @property-read ChangeLogReceiptActions $actions Receipt writes
 */
class ChangeLogReceipt extends DbItem
{
    /**
     * @param string $name Column name
     * @return mixed Scalar column value
     * @throws HilosException When the property does not exist or cannot be accessed
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectChangeLogReceipt::id => $this->_object->id,
            ObjectChangeLogReceipt::created_at => $this->_object->created_at,
            ObjectChangeLogReceipt::actor_user_id => $this->_object->actor_user_id,
            ObjectChangeLogReceipt::subject_user_id => $this->_object->subject_user_id,
            ObjectChangeLogReceipt::session_id => $this->_object->session_id,
            ObjectChangeLogReceipt::channel => $this->_object->channel,
            ObjectChangeLogReceipt::action => $this->_object->action,
            ObjectChangeLogReceipt::agent => $this->_object->agent,
            ObjectChangeLogReceipt::source => $this->_object->source,
            ObjectChangeLogReceipt::open_handovers => $this->_object->open_handovers,
            default => parent::__get($name),
        };
    }

    /**
     * Counts journal rows of this receipt through the object item.
     *
     * @return int Journal rows for this receipt
     * @throws DatabaseException When the binding or the count query fails
     */
    public function countEntries(): int
    {
        return $this->_object->countEntries();
    }
}

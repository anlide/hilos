<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Database\Actions\Collection\StatusObservedComponentsActions;
use Hilos\Database\Object\Item\StatusObservedComponent as ObjectStatusObservedComponent;
use Hilos\Database\View\Item\StatusObservedComponent;

/**
 * Write operations for one observation start of a Status component (HIL-1887).
 *
 * Empty on purpose: the row is written once by {@see StatusObservedComponentsActions::record()}
 * and is never changed or removed. The layer is the door through which a project that extended
 * the table with a column of its own would write that column; a subclass does not invent an
 * action layer the base never had (docs/agents/orm/inheritance.md, *The Whole Chain*).
 *
 * @extends DbActions<StatusObservedComponent, ObjectStatusObservedComponent>
 * @property-read ObjectStatusObservedComponent $object
 */
class StatusObservedComponentActions extends DbActions
{
}

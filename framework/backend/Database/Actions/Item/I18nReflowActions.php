<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Database\Actions\Collection\I18nReflowsActions;
use Hilos\Database\Object\Item\I18nReflow as ObjectI18nReflow;
use Hilos\Database\View\Item\I18nReflow;

/**
 * Write operations for the one row of the catalog reflow record (HIL-1472).
 *
 * Empty on purpose: the row is written by {@see I18nReflowsActions::record()} alone. The layer
 * is the door through which a project that extended the record with a column of its own would
 * write that column; a subclass does not invent an action layer the base never had
 * (docs/agents/orm/inheritance.md, *The Whole Chain*).
 *
 * @extends DbActions<I18nReflow, ObjectI18nReflow>
 * @property-read ObjectI18nReflow $object
 */
class I18nReflowActions extends DbActions
{
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\View\Item\UserRename;

/**
 * UserRenameActions - write operations for one row of the framework rename journal (HIL-1196).
 *
 * Empty on purpose: the framework never edits a journal row - it is written once by the rename
 * and removed with the renamed person. The layer is the door through which a project that
 * extended the journal with a column of its own writes that column, so that the write is judged
 * against the row's set like any item write; a subclass does not invent an action layer the
 * base never had (docs/agents/orm/inheritance.md, *The Whole Chain*). The chat demo links a row
 * to the event of its feed through it.
 *
 * @extends DbActions<UserRename, ObjectUserRename>
 * @property-read ObjectUserRename $object
 */
class UserRenameActions extends DbActions
{
}

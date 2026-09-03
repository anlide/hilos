<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\SetOwnershipGuard;
use Hilos\HilosException;

/**
 * Exception: a mounted table does not say whose set its rows are part of.
 *
 * The declaration is a pair of constants on the Entity - `_setVia` naming the column the set
 * is cut by, `_setRoot` saying whether other tables may hang their sets off this one - and a
 * table shipped without them leaves the question "did all the rows of this set arrive" with
 * nobody to ask. The message names every table at fault at once, because the reader is the
 * author of the Entity or of the migration that added it, and one edit answers all of them.
 *
 * Raised by {@see SetOwnershipGuard::assertMountedSetsDeclared()} at the startup of a node,
 * not at the read that needed the set: the gap is born on the day of a migration, and a
 * refusal at the read would find it on the day somebody opened a page.
 *
 * The declaration itself is documented on {@see Entity}.
 */
final class UndeclaredSetOwnershipException extends HilosException
{
}

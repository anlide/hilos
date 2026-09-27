<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\HilosException;

/**
 * Exception: a project's chain mounted under a framework key is not whole.
 *
 * A framework table is extended by subclassing its whole chain and mounting it under the
 * framework's key (docs/agents/orm/inheritance.md), and a chain that got there half-done is what
 * this names: a layer the framework registers for the key still the framework's class, two link
 * constants naming two different Entities, a framework key written over past the substitution
 * point, a subclass that let go of a declaration of the base - its table, primary key, set, a
 * column, a type, a foreign key, an index, a verdict, the collection key - a column with two
 * personal-data verdicts or an added one with none, or one table under two mounted chains. The
 * message names every finding at once, because the reader is the author of the chain and one
 * edit answers all of them.
 *
 * Raised by {@see FrameworkExtensionGuard::assertMountedExtensionsWhole()} at the startup of a
 * node, not at the read that needed the column: a project column that never reached its Object
 * says nothing until the day somebody opens it, and a start refused names the day the chain was
 * written instead.
 *
 * The substitution point refuses what a declaration got wrong on its own, with
 * FrameworkExtensionException; the declaration is documented on
 * {@see HilosDbContext::frameworkExtensions()}.
 */
final class IncompleteFrameworkExtensionException extends HilosException
{
}

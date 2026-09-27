<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\HilosException;

/**
 * Exception: a project's declaration of its chain under a framework key cannot be mounted.
 *
 * Raised by {@see HilosDbContext::configure()} while it mounts the framework's keys, so a node
 * or a CLI refuses to start, and a project's own unit test that builds the context fails at the
 * same place; the message names the key and the layer. The four refusals are the declaration's
 * own: a key the framework does not mount, a value that is not a {@see FrameworkExtension}, a
 * class that does not extend the framework's class of the same layer under that key (the
 * framework's own class included), and an action layer declared for a key the framework
 * registers without one. Whether the chain behind the declaration is whole - every link
 * re-pointed, every column of the base kept, one class over one table - is the question of
 * {@see FrameworkExtensionGuard}, not this one's: two places judging the chain would come to
 * disagree.
 */
final class FrameworkExtensionException extends HilosException
{
}

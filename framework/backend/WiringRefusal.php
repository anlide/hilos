<?php

declare(strict_types=1);

namespace Hilos;

use Hilos\Core\Exception\MalformedInput;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;

/**
 * Marker: this failure is the wiring refusing, not the data answering.
 *
 * An empty interface an exception implements to say what the failure IS, the same way
 * {@see MalformedInput} says an input never parsed. The collection asked for exists and is
 * mounted; what is missing is the declaration that this process reads it. No retry cures that,
 * and no answer is owed — the read was addressed to a process that would go on holding a copy
 * nobody keeps current.
 *
 * Carried by {@see DbCollectionNotReadableException} and {@see RtCollectionNotReadableException},
 * which sit in two exception families and therefore could not share a base class. A marker gives
 * them one name anyway, and one name is what a `catch` and a code-style rule can both say.
 *
 * The marker forbids nothing: `catch (Throwable)` around a `Hilos::$db->` or `Hilos::$rt->` read
 * still swallows it, and PHP has no way to stop that. What stops it is the
 * `WIRING-REFUSAL-SWALLOWED` guard, which reads a broad catch around such a read as a violation
 * and names `catch (WiringRefusal) { throw; }` as one of the three ways out.
 */
interface WiringRefusal
{
}

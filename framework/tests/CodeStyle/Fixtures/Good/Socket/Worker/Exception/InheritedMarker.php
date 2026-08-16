<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Socket\Worker\Exception;

use Hilos\Core\Exception\InvalidFormatException;

/**
 * Negative sample: a parsing failure in a judged directory that declares no marker
 * of its own and needs none, because the base it extends carries one.
 *
 * Inheritance is how the marker reaches most of the classes that hold it, so a rule
 * that asked every class for a declaration of its own would report the whole
 * `require*` branch and the seven frame classes on the day it was turned on.
 */
class InheritedMarker extends InvalidFormatException
{
}

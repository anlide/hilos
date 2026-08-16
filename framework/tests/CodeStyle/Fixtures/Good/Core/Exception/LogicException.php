<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Core\Exception;

use Hilos\HilosException;

/**
 * Negative sample: a class of a judged directory that carries no marker and is meant
 * to, because it says the code is at fault rather than the input.
 *
 * It is the fixture that proves the exempt list is read at all — take its name out of
 * the rule and this file starts failing while every marked neighbour stays quiet.
 */
class LogicException extends HilosException
{
}

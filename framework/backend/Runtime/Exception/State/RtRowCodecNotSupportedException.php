<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception\State;

use Hilos\Runtime\Exception\RtStateException;

/** The concrete row must migrate before entering the resumable Runtime path. */
final class RtRowCodecNotSupportedException extends RtStateException
{
}

<?php

namespace Hilos\Hilos\Runtime\Collection\Exception;

use Hilos\Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException as BaseRtCollectionPropertyNotFoundException;

/**
 * Exception: property not found on RtCollection (e.g. actions, relevantUsers).
 */
class RtCollectionPropertyNotFoundException extends BaseRtCollectionPropertyNotFoundException
{
}

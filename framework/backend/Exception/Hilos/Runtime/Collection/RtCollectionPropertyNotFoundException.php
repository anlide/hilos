<?php

namespace Hilos\Exception\Hilos\Runtime\Collection;

use Hilos\Exception\Runtime\Collection\RtCollectionPropertyNotFoundException as BaseRtCollectionPropertyNotFoundException;

/**
 * Exception: property not found on RtCollection (e.g. actions, relevantUsers).
 */
class RtCollectionPropertyNotFoundException extends BaseRtCollectionPropertyNotFoundException
{
}

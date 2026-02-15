<?php

namespace Hilos\Hilos\Runtime;

use Hilos\Runtime\Idea\IdeaRt as BaseIdeaRt;

/**
 * RtContext - runtime context (transient application data).
 *
 * Holds state collections (e.g. connections) and exposes RtCollection wrappers.
 */
abstract class RtContext extends BaseIdeaRt
{
}

<?php

namespace Hilos\Hilos\Runtime;

use Hilos\Runtime\Idea\IdeaRtItem as BaseIdeaRtItem;

/**
 * RtItem - read-only wrapper around runtime state (Rt layer).
 *
 * Provides high-level access to transient connection/session data.
 */
abstract class RtItem extends BaseIdeaRtItem
{
}

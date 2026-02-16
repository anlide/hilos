<?php

namespace Hilos\Hilos\Database\Item;

use Hilos\Database\Idea\IdeaItem as BaseIdeaItem;

/**
 * DbItem - read-only wrapper around Object instance (Db layer).
 *
 * High-level abstraction with lazy loading and relationships.
 */
abstract class DbItem extends BaseIdeaItem
{
}

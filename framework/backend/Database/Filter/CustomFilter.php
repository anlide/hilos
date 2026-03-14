<?php

namespace Hilos\Database\Filter;

use Hilos\Database\Object\Item\Object_;

/**
 * Base class for custom filters.
 *
 * Extend this class to create custom filter logic.
 * Developer overrides all methods; may contain complex logic (subqueries, DB functions, etc.).
 */
abstract class CustomFilter implements FilterInterface
{
}


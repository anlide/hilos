<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Cluster\Exception;

use Hilos\Cluster\Exception\ClusterException;

/**
 * Deliberately broken sample: an exception about a frame that would not parse,
 * declared in a directory the rule judges, extending a base that carries no marker
 * and named in no exempt list.
 *
 * This is the shape the rule exists for — the class reads as a parsing failure to
 * a human and as an unknown one to the guard on the read path, which is exactly the
 * silence a hand-kept list of class names produces.
 */
class UnmarkedRefusal extends ClusterException
{
}

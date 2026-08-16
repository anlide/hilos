<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Cluster\Exception;

use Hilos\Cluster\Exception\ClusterException;
use Hilos\Core\Exception\MalformedInput;

/**
 * Negative sample: a marked base, in a judged directory, with the marker where it
 * belongs. Neither half of the rule has anything to say about it — which is the
 * point, since both halves read this very declaration.
 */
class PeerTransportException extends ClusterException implements MalformedInput
{
}

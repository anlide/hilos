<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\Actions\Collection\VerifierCircleMembersActions;

/**
 * The collection actions of the test chain: the framework's, inherited whole. Empty on purpose -
 * the layer is subclassed because the framework registers it for the key, not because the project
 * changes what it does.
 */
final class NotedMembersActions extends VerifierCircleMembersActions
{
}

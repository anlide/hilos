<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Fixtures\Extension;

use Hilos\Database\Context\FrameworkExtension;
use Hilos\Database\Context\HilosDbContext;

/**
 * A test project's database context: the framework's keys, with the verifier circle answered by
 * the test chain. The declaration is the whole of what a project writes to extend a framework
 * table; the mount and everything downstream of it is the framework's.
 *
 * @property-read NotedMembers $verifierCircle
 */
final class NotedMembersDbContext extends HilosDbContext
{
    /**
     * @return array<string, FrameworkExtension> The framework's declarations plus the test chain over the verifier circle
     */
    protected function frameworkExtensions(): array
    {
        return [
            ...parent::frameworkExtensions(),
            self::verifierCircle => new FrameworkExtension(
                NotedMembers::class,
                NotedMembersActions::class,
                NotedMemberActions::class,
            ),
        ];
    }
}

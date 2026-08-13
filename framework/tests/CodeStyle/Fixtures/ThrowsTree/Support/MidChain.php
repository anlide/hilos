<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\{NarrowException, OtherException};

/**
 * Two landmines that only go off when a walk resumes in the wrong place.
 *
 * The property below is deliberately named after a class in this very namespace:
 * read as a chain start rather than as a member, `registry` resolves to
 * {@see Registry} and the rule demands the `@throws` of a class the code never
 * mentions. The imports are deliberately grouped, which is the other spelling the
 * alias table has to survive.
 */
final class MidChain
{
    /** @var Registry Receiver whose name collides with a class of this namespace */
    public Registry $registry;

    /**
     * @param Registry $registry Receiver the mid-chain case reads through
     */
    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * The result of a call has no declared type, so everything past it is out of
     * scope — including the property that shares a name with a class.
     *
     * @return string What the value behind the call answered
     */
    public function readsPastACall(): string
    {
        return $this->make()->registry->name();
    }

    /**
     * A group-imported class named by `@throws` has to be recognized, or this reads
     * as a method declaring nothing.
     *
     * @return string What the registry answered
     * @throws OtherException When the entry has gone
     */
    public function coversAGroupImportedClass(): string
    {
        return $this->registry->name();
    }

    /**
     * @return string What the registry matched, undeclared on purpose
     */
    public function missesAGroupImportedClass(): string
    {
        return $this->registry->match('path') ? 'yes' : 'no';
    }

    /**
     * @param string $value Value to guard
     * @return string The value, once it is known to be there
     * @throws NarrowException When the value is absent
     */
    public function guards(string $value): string
    {
        return NarrowException::requireValue($value);
    }

    /**
     * @return object Something whose type the chain cannot follow
     */
    private function make(): object
    {
        return new Registry();
    }
}

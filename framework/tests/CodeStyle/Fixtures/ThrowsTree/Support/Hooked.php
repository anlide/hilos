<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

/**
 * A property hook and an attribute, both of which end a member in a way a semicolon
 * does not. The receiver below the hook is the seeded case: it is only reachable if
 * the walk stopped where the hook ends instead of running on to the next semicolon.
 */
final class Hooked
{
    /** A hooked property carries no semicolon of its own. */
    public string $label {
        get => 'label';
    }

    /** A hook may sit behind a default value, and the block still ends the member. */
    public string $tag = 'tag' {
        get => $this->tag;
    }

    /** @var Registry Receiver declared directly after the hooked property */
    private Registry $backing;

    /**
     * @param Registry $backing Receiver the hook must not have swallowed
     */
    public function __construct(Registry $backing)
    {
        $this->backing = $backing;
    }

    #[SinceAttribute([1, 2])]
    private Registry $afterAnArrayAttribute;

    /**
     * @return string What the receiver declared after an attribute holding an array answered
     */
    public function readsPastAnAttributeArray(): string
    {
        return $this->afterAnArrayAttribute->name();
    }

    /**
     * @return string What the receiver declared after the hook answered
     */
    #[SinceAttribute]
    public function readsTheMemberAfterTheHook(): string
    {
        return $this->backing->name();
    }
}

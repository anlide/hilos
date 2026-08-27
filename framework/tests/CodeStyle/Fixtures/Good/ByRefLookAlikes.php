<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

/**
 * Negative sample of what the rule is obliged to let through. Every `&` below is a
 * legitimate reference outside the wrapper layer — the three shapes the repository
 * actually uses — so a single reported line here means VIEW-WRAPPER-BIND has grown
 * past the zone it is written for.
 */
final class ByRefLookAlikes
{
    /**
     * An accumulator filled by the callee: the parameter is the answer, and this file
     * stands outside the View zones, where that is how the repository writes it.
     *
     * @param array<int, string> $collected List the callee appends to
     */
    public function accumulate(array &$collected): void
    {
        $collected[] = 'appended';
    }

    /**
     * A closure capturing a variable by reference. The rule reads parameter lists, and
     * a capture list is not one, so this stays silent on every root.
     *
     * @return string What the closure wrote back into the captured variable
     */
    public function captured(): string
    {
        $written = '';
        $write = static function () use (&$written): void {
            $written = 'from the closure';
        };
        $write();

        return $written;
    }

    /**
     * A reference to a local. Half one reads `$this-><name>`, so a local aliasing
     * another local is not a wrapper binding.
     *
     * @return string Value read back through the alias
     */
    public function localAlias(): string
    {
        $original = 'value';
        $alias = &$original;

        return $alias;
    }
}

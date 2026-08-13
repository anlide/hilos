<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;
use Throwable;
use Vendor\Outside\UnknownVendorException;

/**
 * Three shapes that used to make the rule answer from the wrong place, all of which
 * must stay silent: a loop variable shadowing a parameter, a `catch (Throwable)` over
 * a class no scanned root declares, and a child reusing the name of a private parent
 * method.
 */
final class Shadowed extends PrivateParent
{
    /**
     * Both readings exist and they disagree, so neither is written down and the call
     * is out of scope rather than judged against the parameter.
     *
     * @param SourceInterface $item Source handed in, whose name the loop takes over
     * @param array<int, Registry> $registries Registries the loop walks
     * @return int How many answered
     */
    public function loopShadowsTheParameter(SourceInterface $item, array $registries): int
    {
        $answered = 0;
        foreach ($registries as $item) {
            $item->read();
            $answered++;
        }

        return $answered;
    }

    /**
     * The loop variable is assigned again below the loop, so it holds two things at
     * two places and the index has no ranges to tell them apart.
     *
     * @param array<int, Registry> $registries Registries the loop walks
     * @return string What the reassigned local answered
     */
    public function reassignsTheLoopVariable(array $registries): string
    {
        foreach ($registries as $entry) {
            $entry->name();
        }
        $entry = $this->outside();

        return $entry->read();
    }

    /**
     * `catch (Throwable)` absorbs anything at all, including a class the index never
     * saw and can place nowhere in the hierarchy.
     *
     * @return string What the outside answered, or the fallback
     */
    public function catchesAnUnknownClass(): string
    {
        try {
            return $this->reachOutside();
        } catch (Throwable) {
            return 'fallback';
        }
    }

    /**
     * @return string A constant
     * @throws OtherException Never, in a fixture
     */
    public function hidden(): string
    {
        return 'hidden';
    }

    /**
     * @return mixed Something whose type is not written down
     */
    private function outside(): mixed
    {
        return null;
    }

    /**
     * @return string Nothing; the private link only exists to raise an unplaceable class
     */
    private function reachOutside(): string
    {
        throw new UnknownVendorException('outside');
    }
}

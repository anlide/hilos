<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree;

use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\AbstractSource;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\OtherException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\TreeException;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support\Constructed;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support\HelperTrait;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support\Indexed;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support\Registry;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support\SinceAttribute;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support\WidenedConstruct;
use Throwable;

/**
 * One judged call form per method, each seeded to be caught or to stay silent on
 * purpose. Nothing here is ever loaded — the tree exists to be tokenized.
 */
final class Caller extends AbstractSource
{
    use HelperTrait;

    /** @var ?Registry Static-property receiver with a declared type */
    public static ?Registry $registry = null;

    /** @var list<SourceInterface> Sources the loop case iterates */
    private array $sources = [];

    /** @var array<int, SourceInterface> Sources declared in the spelling that carries a space */
    private array $keyedSources = [];

    /**
     * @param SourceInterface $source Source the direct cases call through
     */
    public function __construct(private readonly SourceInterface $source)
    {
    }

    /**
     * @return string Payload read from the source
     * @throws NarrowException When the source refuses to answer
     */
    public function read(): string
    {
        return $this->source->read();
    }

    /**
     * @return string What the source answered
     */
    public function missesTheTag(): string
    {
        return $this->source->read();
    }

    /**
     * @return string What the source answered
     * @throws TreeException When anything below the call refuses
     */
    public function coversWithTheBase(): string
    {
        return $this->source->read();
    }

    /**
     * @return bool True when the parent got the source up
     * @throws NarrowException When the source refuses to answer
     */
    public function narrowsTheBase(): bool
    {
        return parent::start();
    }

    /**
     * @return string What the source answered, or the fallback
     */
    public function catchesIt(): string
    {
        try {
            return $this->source->read();
        } catch (TreeException) {
            return 'fallback';
        }
    }

    /**
     * @return string What the source answered, or the fallback
     */
    public function catchesThrowable(): string
    {
        try {
            return $this->source->read();
        } catch (Throwable) {
            return 'fallback';
        }
    }

    /**
     * @return string What the source answered
     */
    public function convertsInTheCatch(): string
    {
        try {
            return $this->source->read();
        } catch (NarrowException) {
            throw new OtherException('converted');
        }
    }

    /**
     * @return string What the private link answered
     */
    public function goesThroughAPrivateLink(): string
    {
        return $this->readThroughHelper();
    }

    /**
     * @return string A constant, because the body of the closure is not this method's
     */
    public function keepsTheClosureOut(): string
    {
        $read = function (): string {
            return $this->source->read();
        };

        return $read();
    }

    /**
     * @return string A constant, because the body of the arrow function is not this method's
     */
    public function keepsTheArrowFunctionOut(): string
    {
        $read = fn(): string => $this->source->read();

        return $read();
    }

    /**
     * @return string What the registry answered
     */
    public function callsByClassName(): string
    {
        return Registry::lookup('key');
    }

    /**
     * @return string What the shared registry answered
     */
    public function callsThroughAStaticProperty(): string
    {
        return self::$registry->name();
    }

    /**
     * Uses the trait's own contract without restating it.
     */
    public function callsATraitMethod(): void
    {
        $this->helpFromTrait();
    }

    /**
     * @return int How many sources answered
     */
    public function iteratesADeclaredArray(): int
    {
        $answered = 0;
        foreach ($this->sources as $source) {
            $source->read();
            $answered++;
        }

        return $answered;
    }

    /**
     * @return Constructed Value the constructor either made or refused to make
     */
    public function constructsAThrowingClass(): Constructed
    {
        return new Constructed('name');
    }

    /**
     * Raises the tree's own exception without saying so.
     */
    public function throwsWithoutSayingSo(): void
    {
        throw new NarrowException('raised');
    }

    /**
     * @param object $unknown Receiver nothing declares a class for
     * @return string A constant, because the call is out of scope
     */
    public function keepsAnUndeclaredReceiverOut(object $unknown): string
    {
        $unknown->read();

        return 'unknown';
    }

    /**
     * @return int How many keyed sources answered
     */
    public function iteratesASpacedGenericArray(): int
    {
        $answered = 0;
        foreach ($this->keyedSources as $source) {
            $source->read();
            $answered++;
        }

        return $answered;
    }

    /**
     * @param array<string, SourceInterface> $byName Sources handed in, keyed by name
     * @return int How many of them answered
     */
    public function iteratesAParameterArray(array $byName): int
    {
        $answered = 0;
        foreach ($byName as $source) {
            $source->read();
            $answered++;
        }

        return $answered;
    }

    /**
     * Two loops, one variable name, two receivers: nothing declares what `$item` is,
     * so both calls are out of scope rather than judged against the last binding.
     *
     * @param array<int, Registry> $registries Registries handed in
     * @return int How many answered
     */
    public function reusesOneLoopVariable(array $registries): int
    {
        $answered = 0;
        foreach ($this->sources as $item) {
            $item->read();
            $answered++;
        }
        foreach ($registries as $item) {
            $item->name();
            $answered++;
        }

        return $answered;
    }

    /**
     * @return callable A closure over the reader; nothing is thrown until it is called
     */
    public function makesAFirstClassCallable(): callable
    {
        return $this->source->read(...);
    }

    /**
     * @return WidenedConstruct A value whose constructor documents more than the base one
     * @throws NarrowException When the name is refused for a reason of the subclass
     * @throws OtherException When the name is refused
     */
    public function constructsAWidenedSubclass(): WidenedConstruct
    {
        return new WidenedConstruct('name');
    }

    /**
     * @return bool What the registry matched
     */
    public function callsAKeywordNamedMethod(): bool
    {
        return self::$registry->match('path');
    }

    /**
     * @return int How many entries the registry handed back
     */
    public function callsAByReferenceMethod(): int
    {
        return count(self::$registry->entries());
    }

    /**
     * @param SourceInterface $source Source reached past an attribute on the parameter
     * @return string What the source answered
     */
    public function readsPastAParameterAttribute(#[SinceAttribute] SourceInterface $source): string
    {
        return $source->read();
    }

    /**
     * @param Indexed $indexed Catalog read through the short form
     * @return mixed What the key held
     */
    public function readsAnIndex(Indexed $indexed): mixed
    {
        return $indexed['key'];
    }

    /**
     * @param Indexed $indexed Catalog written through the short form
     */
    public function writesAnIndex(Indexed $indexed): void
    {
        $indexed['key'] = 'value';
    }

    /**
     * @param Indexed $indexed Catalog asked whether it holds the key
     * @return bool True when the key is there
     */
    public function testsAnIndex(Indexed $indexed): bool
    {
        return isset($indexed['key']);
    }

    /**
     * The second spelling of the same door, and a token of its own rather than a
     * synonym of `isset` — so it is seeded rather than assumed.
     *
     * @param Indexed $indexed Catalog asked whether the key holds anything
     * @return bool True when the key holds nothing
     */
    public function emptiesAnIndex(Indexed $indexed): bool
    {
        return empty($indexed['key']);
    }

    /**
     * @param Indexed $indexed Catalog the key is dropped from
     */
    public function dropsAnIndex(Indexed $indexed): void
    {
        unset($indexed['key']);
    }

    /**
     * The second key is the one at risk: an interpolation closes with a plain brace and
     * opens with a token of its own, so a walk that counted only the closer would look
     * for the construct past the `isset` and read this as a plain index.
     *
     * @param Indexed $indexed Catalog asked about two keys at once
     * @param string $prefix Prefix interpolated into the first key
     * @return bool True when both keys are there
     */
    public function testsTwoIndexesPastAnInterpolation(Indexed $indexed, string $prefix): bool
    {
        return isset(
            $indexed["{$prefix}-one"],
            $indexed['two']
        );
    }

    /**
     * An array of a class that does have an index: the element type is what the guard
     * would reach, and the array standing before it is what keeps it away.
     *
     * @return SourceInterface The first source
     */
    public function readsAnArrayProperty(): SourceInterface
    {
        return $this->sources[0];
    }

    /**
     * @param string $text Text one character is taken out of
     * @return string That character
     */
    public function readsAStringOffset(string $text): string
    {
        return $text[0];
    }

    /**
     * @param object $unknown Receiver nothing declares a class for
     * @return mixed Whatever the key held
     */
    public function readsAnIndexOnAnUndeclaredReceiver(object $unknown): mixed
    {
        return $unknown['key'];
    }

    /**
     * @param Registry $registry Receiver whose class has no index at all
     * @return mixed Whatever the key held
     */
    public function readsAnIndexOnAClassWithoutOne(Registry $registry): mixed
    {
        return $registry['key'];
    }

    /**
     * The private link the rule walks through instead of asking a tag of it.
     *
     * @return string What the source answered
     */
    private function readThroughHelper(): string
    {
        return $this->source->read();
    }
}

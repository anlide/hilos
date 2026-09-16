<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Support;

use Closure;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Contract\SourceInterface;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Exception\NarrowException;

/**
 * Seeds the silence of the third direction past every entry the index sees and cannot
 * follow. Each method writes one resolved call that raises nothing and one such entry
 * the tag really comes through; read without the entry, every tag here would look
 * left behind.
 */
final class OpaqueHolder
{
    /**
     * @param Quiet $quiet Source that raises nothing
     * @param SourceInterface $source Source the entries reach
     */
    public function __construct(
        private readonly Quiet $quiet,
        private readonly SourceInterface $source,
    ) {
    }

    /**
     * @param string $name Property nobody declared
     * @return string What the source answered
     * @throws NarrowException When the source refuses to answer
     */
    public function __get(string $name): string
    {
        return $this->source->read();
    }

    /**
     * @return string What the source answered through a function
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsATagPastAFunction(): string
    {
        $this->quiet->speak();

        return (string)call_user_func([$this->source, 'read']);
    }

    /**
     * @return string What the source a helper returned answered
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsATagPastACallOnAResult(): string
    {
        $this->quiet->speak();

        return $this->currentSource()->read();
    }

    /**
     * @param NarrowException $refusal Refusal raised when the quiet source says nothing
     * @return string What the quiet source said
     * @throws NarrowException When the quiet source says nothing
     */
    public function keepsATagPastAThrownValue(NarrowException $refusal): string
    {
        if ($this->quiet->speak() === '') {
            throw $refusal;
        }

        return 'spoken';
    }

    /**
     * The reader is this method's own work handed out, and the index cannot tell
     * whether it is called here or by whoever takes it.
     *
     * @return Closure(): string Reader of the source
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsATagPastAClosure(): Closure
    {
        $this->quiet->speak();

        return fn(): string => $this->source->read();
    }

    /**
     * @param Closure(): string $read Reader the caller hands in
     * @return string What the reader answered
     * @throws NarrowException When the reader refuses to answer
     */
    public function keepsATagPastACalleeInAVariable(Closure $read): string
    {
        $this->quiet->speak();

        return $read();
    }

    /**
     * @param string $member Member name, reaching {@see self::__get()}
     * @return string What the source answered through the magic read
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsATagPastAMemberNamedByAVariable(string $member): string
    {
        $this->quiet->speak();

        return (string)$this->{$member};
    }

    /**
     * @param class-string<SourceInterface> $class Source class whose constructor reads
     * @return SourceInterface A fresh source
     * @throws NarrowException When the source refuses to be made
     */
    public function keepsATagPastAClassInAVariable(string $class): SourceInterface
    {
        $this->quiet->speak();

        return new $class();
    }

    /**
     * @return object A reader built over the source
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsATagPastAnAnonymousClass(): object
    {
        $this->quiet->speak();

        return new class ($this->source) {
            /**
             * @param SourceInterface $source Source read on construction
             * @throws NarrowException When the source refuses to answer
             */
            public function __construct(SourceInterface $source)
            {
                $source->read();
            }
        };
    }

    /**
     * @return Closure(): string Reader of the source
     * @throws NarrowException When the source refuses to answer
     */
    public function keepsATagPastAFirstClassCallable(): Closure
    {
        $this->quiet->speak();

        return $this->source->read(...);
    }

    /**
     * @return SourceInterface The source the entries reach
     */
    private function currentSource(): SourceInterface
    {
        return $this->source;
    }
}

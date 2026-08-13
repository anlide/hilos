<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * One place in a method body where an exception can enter it: a call whose target
 * the index recorded verbatim, or a `throw new` that raises one outright.
 *
 * The receiver is kept as it was written and resolved later, because resolving it
 * needs the whole tree: `$this->registry->find()` is a property of one class typed
 * with another, and the second may live in a different root.
 */
final readonly class CallSite
{
    /** A method call: {@see self::$base}/{@see self::$path} name the receiver, {@see self::$target} the method. */
    public const string KIND_CALL = 'call';

    /** A `new X(...)`: the target is the class, and the contract judged is its constructor. */
    public const string KIND_NEW = 'new';

    /** A `throw new X(...)`: the target is the exception class raised. */
    public const string KIND_THROW = 'throw';

    /** Marks a step of {@see self::$path} as a static property rather than an instance one. */
    public const string STATIC_STEP_PREFIX = '$';

    /**
     * @param string $kind One of the KIND_* constants
     * @param int $line Line the call sits on
     * @param string $base Receiver root: `$this`, `self`, `static`, `parent`, `$name`, a class name, or empty
     * @param array<int, string> $path Property steps from the base, a leading `$` marking a static one
     * @param string $target Method name for a call, fully qualified class name for a `new` or a `throw`
     * @param array<int, string> $caught Fully qualified exception types an enclosing `catch` swallows here
     */
    public function __construct(
        public string $kind,
        public int $line,
        public string $base,
        public array $path,
        public string $target,
        public array $caught,
    ) {
    }

    /**
     * The chain reader knows what is called; only the walk around it knows which
     * `try` blocks stand over that token.
     *
     * @param array<int, string> $caught Fully qualified exception types an enclosing `catch` swallows here
     * @return self Same call, with the swallowed types filled in
     */
    public function withCaught(array $caught): self
    {
        return new self($this->kind, $this->line, $this->base, $this->path, $this->target, $caught);
    }
}

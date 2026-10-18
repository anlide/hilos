<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * One place a method body reads `$options` by key, or passes that variable whole
 * into a call.
 *
 * Class names are already resolved. The index does it while the file's imports
 * are still known, and it does not keep that table afterwards, so a rule cannot
 * resolve `BackupConstants::SCOPE_OPTION` from the text alone.
 *
 * A key carries either a string literal, a `self` / `static` / `parent` constant,
 * or a fully qualified class and a constant name. Anything else is unnamed: the
 * guard can see that a key was read and cannot say which. A handoff carries the
 * callee instead of a key. A direct method of the enclosing class is still
 * recorded; the rule is what decides that `$this`, `self`, `static` and `parent`
 * are not findings.
 */
final readonly class OptionAccess
{
    /** A key written as a literal or a constant. */
    public const string KIND_KEY = 'key';

    /** A key the index cannot name: a variable, a call, a concatenation. */
    public const string KIND_UNNAMED = 'unnamed';

    /** `$options` passed whole as one argument of a call. */
    public const string KIND_HANDOFF = 'handoff';

    /** Callee base of a direct instance call on the enclosing class. */
    public const string BASE_THIS = '$this';

    /** Callee base of a direct `self::` call. */
    public const string BASE_SELF = 'self';

    /** Callee base of a direct `static::` call. */
    public const string BASE_STATIC = 'static';

    /** Callee base of a direct `parent::` call. */
    public const string BASE_PARENT = 'parent';

    /** Callee base of a function, as opposed to a method. */
    public const string BASE_FUNCTION = 'function';

    /** Method name recorded for `new Class($options)`. */
    public const string CONSTRUCT = '__construct';

    /**
     * @param string $kind One of the KIND_* constants
     * @param int $line Line the read or the handoff sits on
     * @param ?string $literal Option name when the key is a string literal
     * @param ?string $scope `self`, `static` or `parent` when the key is a relative constant
     * @param ?string $class Fully qualified class of a `Class::CONST` key
     * @param ?string $constant Constant name of a key written as a constant
     * @param ?string $calleeBase Receiver of a handoff: a BASE_* constant, a fully qualified class, or empty
     * @param ?string $callee Method or function a handoff calls
     */
    public function __construct(
        public string $kind,
        public int $line,
        public ?string $literal,
        public ?string $scope,
        public ?string $class,
        public ?string $constant,
        public ?string $calleeBase,
        public ?string $callee,
    ) {
    }
}

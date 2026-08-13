<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * One method as the index read it: the contract it declares and every place in its
 * body an exception can enter from.
 */
final readonly class MethodRecord
{
    public const string VISIBILITY_PUBLIC = 'public';

    public const string VISIBILITY_PROTECTED = 'protected';

    public const string VISIBILITY_PRIVATE = 'private';

    /**
     * @param string $class Fully qualified name of the declaring class, interface or trait
     * @param string $name Method name as written
     * @param string $visibility One of the VISIBILITY_* constants
     * @param bool $hasBody False for an abstract or interface method, whose tags are the whole contract
     * @param int $line Line the declaration sits on
     * @param array<int, string> $throws Fully qualified exception classes named by `@throws`
     * @param array<int, CallSite> $callSites Calls and throws found in the body, in source order
     * @param array<string, string> $variableTypes Declared type by variable name, an `[]` suffix marking an array of it
     * @param array<string, array{base: string, path: array<int, string>}> $arrayBindings Iterated receiver by loop variable
     */
    public function __construct(
        public string $class,
        public string $name,
        public string $visibility,
        public bool $hasBody,
        public int $line,
        public array $throws,
        public array $callSites,
        public array $variableTypes,
        public array $arrayBindings,
    ) {
    }

    /**
     * A private helper is not a contract: the rule walks through it into its body,
     * so its own `@throws` is never the answer to what a caller owes.
     *
     * @return bool True when the method is a private link rather than a contract
     */
    public function isPrivateLink(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }
}

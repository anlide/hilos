<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * Answers the one question the rule asks about exception classes: does a declared
 * type cover a thrown one.
 *
 * The tree is built from the classes of our own roots plus the table below, and
 * nothing else. Vendor and built-in classes are not indexed — a `use` of one names
 * a file no scanned root holds — so the roots of the PHP hierarchy are written out
 * here instead. A class the tree does not know covers only itself, which keeps the
 * rule narrow rather than wrong.
 */
final class ExceptionHierarchy
{
    /** The top of the hierarchy, which covers anything at all — including what the tree never saw. */
    private const string THROWABLE = 'Throwable';

    /**
     * Built-in exception classes and what each of them is, keyed without a leading
     * backslash the way the index stores a resolved name. `Throwable` has no entry
     * of its own because it is the top: everything below reaches it through a
     * parent, and a class outside this table reaches nothing.
     *
     * @var array<string, array<int, string>>
     */
    private const array BUILTIN_PARENTS = [
        'Exception' => [self::THROWABLE],
        'Error' => [self::THROWABLE],
        'ErrorException' => ['Exception'],
        'JsonException' => ['Exception'],
        'ReflectionException' => ['Exception'],
        'Random\RandomException' => ['Exception'],
        'LogicException' => ['Exception'],
        'RuntimeException' => ['Exception'],
        'BadFunctionCallException' => ['LogicException'],
        'DomainException' => ['LogicException'],
        'InvalidArgumentException' => ['LogicException'],
        'LengthException' => ['LogicException'],
        'OutOfRangeException' => ['LogicException'],
        'BadMethodCallException' => ['BadFunctionCallException'],
        'OutOfBoundsException' => ['RuntimeException'],
        'OverflowException' => ['RuntimeException'],
        'PDOException' => ['RuntimeException'],
        'RangeException' => ['RuntimeException'],
        'UnderflowException' => ['RuntimeException'],
        'UnexpectedValueException' => ['RuntimeException'],
        'ArithmeticError' => ['Error'],
        'AssertionError' => ['Error'],
        'TypeError' => ['Error'],
        'UnhandledMatchError' => ['Error'],
        'ValueError' => ['Error'],
        'DivisionByZeroError' => ['ArithmeticError'],
        'ArgumentCountError' => ['TypeError'],
    ];

    /** @var array<string, array<int, string>> Memoized ancestor sets, keyed by lowercased class name */
    private array $ancestors = [];

    /**
     * @param SourceIndex $index Indexed tree the project's own exception classes come from
     */
    public function __construct(private readonly SourceIndex $index)
    {
    }

    /**
     * A `@throws` may name the exception itself or any of its ancestors: the document
     * allows a base class when the caller only needs the category, and refuses the
     * reverse — a narrow tag over a wider thrown type says something untrue.
     *
     * @param string $declared Fully qualified class named by the `@throws` or caught by a `catch`
     * @param string $thrown Fully qualified class that actually reaches the caller
     * @return bool True when declaring the first is a truthful answer for the second
     */
    public function covers(string $declared, string $thrown): bool
    {
        if (strcasecmp($declared, $thrown) === 0) {
            return true;
        }
        // Everything that can be thrown is a Throwable, including a class the tree does
        // not hold — without this, `catch (Throwable)` would fail to absorb one.
        if (strcasecmp(ltrim($declared, '\\'), self::THROWABLE) === 0) {
            return true;
        }

        foreach ($this->ancestorsOf($thrown) as $ancestor) {
            if (strcasecmp($declared, $ancestor) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $class Fully qualified class name
     * @return array<int, string> Every parent class and interface above it, project tree first
     */
    private function ancestorsOf(string $class): array
    {
        $key = strtolower($class);
        if (isset($this->ancestors[$key])) {
            return $this->ancestors[$key];
        }

        // A cycle is impossible in valid PHP, but the index reads text and not a loaded tree.
        $this->ancestors[$key] = [];
        $collected = [];
        foreach ($this->directParentsOf($class) as $parent) {
            $collected[strtolower($parent)] = $parent;
            foreach ($this->ancestorsOf($parent) as $above) {
                $collected[strtolower($above)] = $above;
            }
        }
        $this->ancestors[$key] = array_values($collected);

        return $this->ancestors[$key];
    }

    /**
     * @param string $class Fully qualified class name
     * @return array<int, string> Its immediate parent and interfaces
     */
    private function directParentsOf(string $class): array
    {
        $record = $this->index->find($class);
        if ($record !== null) {
            return $record->parent === null ? $record->interfaces : [$record->parent, ...$record->interfaces];
        }

        return self::BUILTIN_PARENTS[ltrim($class, '\\')] ?? [];
    }
}

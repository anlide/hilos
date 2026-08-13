<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * Turns a call site back into the declaration it reaches, for the forms whose target
 * is known without inferring a single type.
 *
 * Everything it cannot answer it answers with null, and a null is silence rather than
 * a pass: the rule is allowed to be narrower than the document it enforces and is
 * never allowed to be wider, so a receiver whose type is not written down anywhere is
 * left alone instead of guessed at.
 */
final readonly class CallResolver
{
    /** Suffix the index puts on a declared type to mark an array of it. */
    private const string ARRAY_SUFFIX = '[]';

    /**
     * @param SourceIndex $index Indexed tree the target is looked up in
     */
    public function __construct(private SourceIndex $index)
    {
    }

    /**
     * @param ClassRecord $class Class the call is written in
     * @param MethodRecord $method Method the call is written in
     * @param CallSite $site Call to resolve
     * @return ?MethodRecord Declaration the call reaches, or null when the target is out of scope
     */
    public function resolve(ClassRecord $class, MethodRecord $method, CallSite $site): ?MethodRecord
    {
        if ($site->kind === CallSite::KIND_THROW) {
            return null;
        }
        if ($site->kind === CallSite::KIND_NEW) {
            return $this->index->resolveMethod($site->target, '__construct');
        }

        $receiver = $this->chainType($class, $method, $site->base, $site->path, []);
        if ($receiver === null || str_ends_with($receiver, self::ARRAY_SUFFIX)) {
            return null;
        }

        return $this->index->resolveMethod($receiver, $site->target);
    }

    /**
     * Walks `$this->registry->store` one declared property at a time. A step whose type
     * is an array ends the walk — there is no `->` on an array — but an array at the
     * very end is handed back, because that is what a `foreach` iterates.
     *
     * @param ClassRecord $class Class the chain is written in
     * @param MethodRecord $method Method the chain is written in
     * @param string $base Receiver root as the index recorded it
     * @param array<int, string> $path Property steps from the base
     * @param array<int, string> $seen Loop variables already resolved, guarding a self-referential `foreach`
     * @return ?string Declared type of the chain's end, or null when a step is not declared
     */
    private function chainType(ClassRecord $class, MethodRecord $method, string $base, array $path, array $seen): ?string
    {
        $current = $this->baseType($class, $method, $base, $seen);
        foreach ($path as $step) {
            if ($current === null || str_ends_with($current, self::ARRAY_SUFFIX)) {
                return null;
            }
            $current = $this->lookupProperty($current, $step, []);
        }

        return $current;
    }

    /**
     * @param ClassRecord $class Class the chain is written in
     * @param MethodRecord $method Method the chain is written in
     * @param string $base Receiver root as the index recorded it
     * @param array<int, string> $seen Loop variables already resolved
     * @return ?string Declared type the chain starts from
     */
    private function baseType(ClassRecord $class, MethodRecord $method, string $base, array $seen): ?string
    {
        return match (true) {
            $base === '$this', $base === 'self', $base === 'static' => $class->name,
            $base === 'parent' => $class->parent,
            str_starts_with($base, '$') => $this->variableType($class, $method, $base, $seen),
            default => $base,
        };
    }

    /**
     * A parameter carries its type in the signature; a loop variable carries it in the
     * element type of whatever the `foreach` iterates.
     *
     * @param ClassRecord $class Class the variable lives in
     * @param MethodRecord $method Method the variable lives in
     * @param string $variable Variable name, dollar included
     * @param array<int, string> $seen Loop variables already resolved
     * @return ?string Declared type of the variable, or null when nothing declares it
     */
    private function variableType(ClassRecord $class, MethodRecord $method, string $variable, array $seen): ?string
    {
        $declared = $method->variableTypes[$variable] ?? null;
        $binding = $method->arrayBindings[$variable] ?? null;
        if ($declared !== null) {
            // A loop that reuses a parameter's name leaves neither type written down.
            return $binding === null ? $declared : null;
        }
        if ($binding === null || in_array($variable, $seen, true)) {
            return null;
        }

        $seen[] = $variable;
        $iterated = $this->chainType($class, $method, $binding['base'], $binding['path'], $seen);
        if ($iterated === null || !str_ends_with($iterated, self::ARRAY_SUFFIX)) {
            return null;
        }

        return substr($iterated, 0, -strlen(self::ARRAY_SUFFIX));
    }

    /**
     * @param string $class Fully qualified class the property is read on
     * @param string $step Property name, a leading `$` marking a static one
     * @param array<int, string> $visited Classes already looked in, guarding a malformed cycle
     * @return ?string Declared type of the property, or null when it is not declared
     */
    private function lookupProperty(string $class, string $step, array $visited): ?string
    {
        $key = strtolower($class);
        if (in_array($key, $visited, true)) {
            return null;
        }

        $record = $this->index->find($class);
        if ($record === null) {
            return null;
        }
        if (isset($record->propertyTypes[$step])) {
            return $record->propertyTypes[$step];
        }

        $visited[] = $key;
        $sources = $record->parent === null ? $record->traits : [...$record->traits, $record->parent];
        foreach ($sources as $source) {
            $found = $this->lookupProperty($source, $step, $visited);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}

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
     * Magic receivers the rule judges. The list is the boundary: a second receiver
     * is added here explicitly rather than inferred by the mechanism.
     */
    private const array MAGIC_PROPERTY_CONSTANTS = ['objectCollection' => 'OBJECT_COLLECTION_CLASS'];

    /**
     * Built-in roots whose constructors raise nothing, so a class in the index that
     * inherits its constructor from one of them has been read to the end.
     */
    private const array SILENT_BUILTIN_ROOTS = ['Exception', 'Error'];

    private ExceptionHierarchy $hierarchy;

    /**
     * @param SourceIndex $index Indexed tree the target is looked up in
     */
    public function __construct(private SourceIndex $index)
    {
        $this->hierarchy = new ExceptionHierarchy($index);
    }

    /**
     * @param ClassRecord $class Class the call is written in
     * @param MethodRecord $method Method the call is written in
     * @param CallSite $site Call to resolve
     * @return ?MethodRecord Declaration the call reaches, or null when the target is out of scope
     */
    public function resolve(ClassRecord $class, MethodRecord $method, CallSite $site): ?MethodRecord
    {
        if ($site->kind === CallSite::KIND_THROW || $site->kind === CallSite::KIND_OPAQUE) {
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
     * Tells the two meanings of a null {@see self::resolve()} apart. "The receiver is
     * written down nowhere" leaves the call unread, and a claim about what the body
     * cannot throw is no longer honest; "the class is known and declares no
     * constructor" reads the call to the end — there is simply nothing in it to raise.
     *
     * A constructor inherited from a class outside the index is not known to raise
     * nothing, unless that class is a built-in exception or error. An opaque entry is
     * never read to its target: that is what the index recorded it for.
     *
     * A call reached through a magic property is not read to the end either, even when
     * a constant or a class-level tag names the property's class: the read runs
     * `__get()`, and the walk trusts the type the tag writes down, not the body behind it.
     *
     * @param ClassRecord $class Class the call is written in
     * @param MethodRecord $method Method the call is written in
     * @param CallSite $site Call to resolve
     * @return bool True when the call was read to its target, a `throw` and a constructor-less class included
     */
    public function resolves(ClassRecord $class, MethodRecord $method, CallSite $site): bool
    {
        if ($site->kind === CallSite::KIND_THROW) {
            return true;
        }
        if ($site->kind === CallSite::KIND_OPAQUE) {
            return false;
        }
        if ($site->kind !== CallSite::KIND_NEW) {
            return $this->resolve($class, $method, $site) !== null && !$this->readsAMagicProperty($class, $method, $site);
        }
        if ($this->resolve($class, $method, $site) !== null) {
            return true;
        }

        $seen = [];
        $current = $this->index->find($site->target);
        while ($current !== null && !isset($seen[strtolower($current->name)])) {
            if ($current->parent === null) {
                return true;
            }
            $seen[strtolower($current->name)] = true;
            $parent = $current->parent;
            $current = $this->index->find($parent);
            if ($current === null) {
                return $this->isSilentBuiltin($parent);
            }
        }

        return false;
    }

    /**
     * @param ClassRecord $class Class the call is written in
     * @param MethodRecord $method Method the call is written in
     * @param CallSite $site Call whose receiver chain is walked
     * @return bool True when a step of the chain is a property no class along the way declares
     */
    private function readsAMagicProperty(ClassRecord $class, MethodRecord $method, CallSite $site): bool
    {
        $current = $this->baseType($class, $method, $site->base, []);
        foreach ($site->path as $step) {
            if ($current === null || str_ends_with($current, self::ARRAY_SUFFIX)) {
                return false;
            }
            if (!$this->declaresProperty($current, $step, [])) {
                return true;
            }
            $current = $this->lookupProperty($current, $step, []);
        }

        return false;
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
            $class = $current;
            $current = $this->lookupProperty($class, $step, []);
            if ($current === null) {
                $current = $this->magicPropertyType($class, $step);
            }
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
     * @param string $class Fully qualified class the magic property is read on
     * @param string $step Property name
     * @return ?string Declared magic-property type, or null when this receiver is outside the rule
     */
    private function magicPropertyType(string $class, string $step): ?string
    {
        $constant = self::MAGIC_PROPERTY_CONSTANTS[$step] ?? null;

        return $constant === null ? null : $this->index->resolveConstantClass($class, $constant);
    }

    /**
     * Asks each class of the chain its real declaration first and its class-level tag
     * second, and only then climbs to the traits and the parent, so the record on the
     * class itself outranks the one it inherits.
     *
     * A tag is taken only when the index holds the class it names. `DbCollection`
     * writes `@property-read TObjectCollection $objectCollection`, a generic placeholder
     * no root declares; answering with it would end the search before
     * {@see self::magicPropertyType()} is ever asked, and every receiver whose class
     * a constant names would go silent again.
     *
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
        $tagged = $record->docPropertyTypes[$step] ?? null;
        if ($tagged !== null && $this->index->find($this->elementType($tagged)) !== null) {
            return $tagged;
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

    /**
     * Asks only for a real declaration, a promoted parameter included, and never for a
     * class-level tag: a tag describes what `__get()` hands back.
     *
     * @param string $class Fully qualified class the property is read on
     * @param string $step Property name, a leading `$` marking a static one
     * @param array<int, string> $visited Classes already looked in, guarding a malformed cycle
     * @return bool True when the class, one of its traits or an ancestor declares the property
     */
    private function declaresProperty(string $class, string $step, array $visited): bool
    {
        $key = strtolower($class);
        $record = $this->index->find($class);
        if ($record === null || in_array($key, $visited, true)) {
            return false;
        }
        if (isset($record->propertyTypes[$step])) {
            return true;
        }

        $visited[] = $key;
        $sources = $record->parent === null ? $record->traits : [...$record->traits, $record->parent];
        foreach ($sources as $source) {
            if ($this->declaresProperty($source, $step, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $type Declared type, an `[]` suffix marking an array of it
     * @return string The class the type names, with the array marker taken off
     */
    private function elementType(string $type): string
    {
        return str_ends_with($type, self::ARRAY_SUFFIX) ? substr($type, 0, -strlen(self::ARRAY_SUFFIX)) : $type;
    }

    /**
     * @param string $class Fully qualified class the index does not hold
     * @return bool True when it is a built-in exception or error, whose constructor raises nothing
     */
    private function isSilentBuiltin(string $class): bool
    {
        foreach (self::SILENT_BUILTIN_ROOTS as $root) {
            if ($this->hierarchy->covers($root, $class)) {
                return true;
            }
        }

        return false;
    }
}

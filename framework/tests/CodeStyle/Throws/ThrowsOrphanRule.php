<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

use Hilos\Tests\CodeStyle\Violation;

/**
 * The third direction of the `@throws` contract of phpdoc.md: a method documents an
 * exception its body cannot throw. {@see ThrowsPropagationRule} asks whether what
 * reaches a caller is written down; this rule asks whether what is written down can
 * still reach it. The tag it finds is left behind — a call was removed and the line
 * above it stayed — and phpdoc.md says why that is worse than a missing one: a wrong
 * tag reads as a reliable answer.
 *
 * The claim "cannot throw" is honest only where the whole body was read, so the rule
 * is silent far more often than it speaks, and each silence has a ground:
 *
 * - **A call did not resolve.** One call into vendor code, or through a receiver no
 *   declaration names, and the method is not judged at all — through a private helper
 *   as much as directly. An entry the index sees and cannot follow counts the same: a
 *   function, a closure, a call on what an expression returned, a callee, member or
 *   class held in a variable, a `throw` of anything but `new`, and a read through a
 *   magic property, whose `__get()` runs unread.
 * - **Nothing can enter.** A body with no call, no `new` and no `throw` is an
 *   extension point, and its tag stands for what an override will raise.
 * - **The inherited contract declares it.** An implementation may repeat what the
 *   declaration it overrides allows, and demanding the tag go would put this rule at
 *   war with the second direction.
 * - **An override needs it.** A base whose tag covers the tag of an override keeps
 *   it, or the second direction would report the override as wider than its base.
 *
 * A constructor gets the first two grounds and not the last two: PHP does not
 * inherit its contract in either direction.
 */
final class ThrowsOrphanRule implements CrossFileRule
{
    public const string ID = 'THROWS-ORPHAN';

    /**
     * What the rule judges, said out loud for the reason {@see ThrowsPropagationRule::SCOPE}
     * is: a green run must not be read as "every tag is backed".
     */
    public const string SCOPE = 'THROWS-ORPHAN judges only a method whose every call resolved into a declaration, and'
        . ' only a tag no reachable exception is related to. A function, a closure, a call on what an expression'
        . ' returned, a callee, member or class held in a variable, a throw of anything but new and a read through'
        . ' __get() are calls that did not resolve. It is silent on a body with no call, new or throw in it'
        . ' at all, which is an extension point rather than a tag left behind; on a tag the inherited contract'
        . ' declares; and on a tag that covers the tag of an override. A method without a body is never judged:';

    private const string DOC = 'docs/agents/code-style/phpdoc.md';

    private SourceIndex $index;

    private BodyExceptions $body;

    /** @var array<string, array<int, ClassRecord>> Classes that extend, implement or use a class, by its lowercased name */
    private array $children = [];

    private function __construct()
    {
    }

    /**
     * @return self Rule that judges every class the index holds
     */
    public static function forWholeIndex(): self
    {
        return new self();
    }

    /**
     * @return string Rule id
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string Owning document
     */
    public function doc(): string
    {
        return self::DOC;
    }

    /**
     * @param SourceIndex $index Indexed source tree of every backend root
     * @return iterable<Violation> One entry per orphaned tag, ordered by file and line
     */
    public function check(SourceIndex $index): iterable
    {
        $this->index = $index;
        $this->body = new BodyExceptions($index);
        $this->children = $this->childrenMap();
        $traits = $this->usedTraits();

        $violations = [];
        $traitVerdicts = [];
        foreach ($index->classes() as $key => $class) {
            if (isset($traits[$key])) {
                // A trait body resolves against the class that uses it, so it is judged there.
                continue;
            }
            foreach ($class->methods as $method) {
                foreach ($this->orphanedTags($class, $method) as $exception) {
                    $violations[] = $this->violation($class, $method, $exception);
                }
            }
            foreach ($index->traitMethods($class) as $provided) {
                $methodKey = strtolower($provided->class . '::' . $provided->name);
                $orphaned = $this->orphanedTags($class, $provided);
                $traitVerdicts[$methodKey] = [
                    'method' => $provided,
                    'orphaned' => isset($traitVerdicts[$methodKey])
                        ? array_values(array_intersect($traitVerdicts[$methodKey]['orphaned'], $orphaned))
                        : $orphaned,
                ];
            }
        }

        // A trait's tag is alive when at least one class using it backs it.
        foreach ($traitVerdicts as $verdict) {
            $declaring = $index->find($verdict['method']->class);
            if ($declaring === null) {
                continue;
            }
            foreach ($verdict['orphaned'] as $exception) {
                $violations[] = $this->violation($declaring, $verdict['method'], $exception);
            }
        }

        usort($violations, static fn(Violation $left, Violation $right): int
            => [$left->relativePath, $left->line, $left->message]
                <=> [$right->relativePath, $right->line, $right->message]);

        yield from $violations;
    }

    /**
     * @param ClassRecord $class Class the body is judged in — the using class for a trait method
     * @param MethodRecord $method Method being judged
     * @return array<int, string> Documented exceptions nothing in the body backs, after every ground for silence
     */
    private function orphanedTags(ClassRecord $class, MethodRecord $method): array
    {
        if ($method->throws === [] || !$method->hasBody || $method->callSites === []) {
            return [];
        }
        if (!$this->body->everyCallResolved($class, $method)) {
            return [];
        }

        $reachable = [];
        foreach ($method->callSites as $site) {
            foreach (array_keys($this->body->reaching($class, $method, $site)) as $exception) {
                $reachable[] = (string)$exception;
            }
        }

        $orphaned = [];
        foreach (array_unique($method->throws) as $tag) {
            if (!$this->isBacked($tag, $reachable) && !$this->isRescued($class, $method, $tag)) {
                $orphaned[] = $tag;
            }
        }

        return $orphaned;
    }

    /**
     * A wide tag over a narrow reachable exception is a coarsening, not a lie; a narrow
     * tag over a wide one is already a hit of the first direction, and a second hit on
     * the same line would say the same thing twice.
     *
     * @param string $tag Exception class the `@throws` names
     * @param array<int, string> $reachable Exception classes the body lets out
     * @return bool True when the hierarchy relates the tag to one of them in either direction
     */
    private function isBacked(string $tag, array $reachable): bool
    {
        foreach ($reachable as $exception) {
            if ($this->body->covers([$tag], $exception) || $this->body->covers([$exception], $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassRecord $class Class the method is judged in
     * @param MethodRecord $method Method whose tag is dead in its own body
     * @param string $tag Exception class the `@throws` names
     * @return bool True when the inherited contract declares the tag or an override needs it
     */
    private function isRescued(ClassRecord $class, MethodRecord $method, string $tag): bool
    {
        // PHP inherits no constructor contract, and a private method is overridden by nothing.
        if ($this->isConstructor($method) || $method->isPrivateLink()) {
            return false;
        }

        $contract = $this->index->resolveInheritedContract($class, $method->name);
        if ($contract !== null && $this->body->covers($contract->throws, $tag)) {
            return true;
        }

        foreach ($this->overridesOf($class, $method->name) as $override) {
            foreach ($override->throws as $overrideTag) {
                if ($this->body->covers([$tag], $overrideTag)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param ClassRecord $class Class whose descendants are walked
     * @param string $method Method name as written
     * @return array<int, MethodRecord> Every declaration below the class that overrides the method
     */
    private function overridesOf(ClassRecord $class, string $method): array
    {
        $name = strtolower($method);
        $overrides = [];
        $seen = [strtolower($class->name) => true];
        $queue = $this->children[strtolower($class->name)] ?? [];
        while ($queue !== []) {
            $descendant = array_shift($queue);
            $key = strtolower($descendant->name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $override = $descendant->methods[$name] ?? $this->providedByTrait($descendant, $name);
            if ($override !== null && !$override->isPrivateLink()) {
                $overrides[] = $override;
            }
            $queue = [...$queue, ...($this->children[$key] ?? [])];
        }

        return $overrides;
    }

    /**
     * @param ClassRecord $class Class that may take the method from a trait
     * @param string $name Lowercased method name
     * @return ?MethodRecord The trait-provided declaration, or null when no trait provides it
     */
    private function providedByTrait(ClassRecord $class, string $name): ?MethodRecord
    {
        if ($class->traits === []) {
            return null;
        }
        foreach ($this->index->traitMethods($class) as $provided) {
            if (strtolower($provided->name) === $name) {
                return $provided;
            }
        }

        return null;
    }

    /**
     * The index walks up only; the rule builds the downward map once per check rather
     * than making the index carry it for every rule that reads it.
     *
     * @return array<string, array<int, ClassRecord>> Direct children by the lowercased name of the class they name
     */
    private function childrenMap(): array
    {
        $children = [];
        foreach ($this->index->classes() as $class) {
            $ancestors = $class->parent === null
                ? [...$class->interfaces, ...$class->traits]
                : [$class->parent, ...$class->interfaces, ...$class->traits];
            foreach ($ancestors as $ancestor) {
                $children[strtolower(ltrim($ancestor, '\\'))][] = $class;
            }
        }

        return $children;
    }

    /**
     * The index does not say which records are traits, but every trait that matters
     * here is named by the class using it; a trait nobody uses has no class to resolve
     * its body against.
     *
     * @return array<string, true> Lowercased names of every trait some class uses
     */
    private function usedTraits(): array
    {
        $traits = [];
        foreach ($this->index->classes() as $class) {
            foreach ($class->traits as $trait) {
                $traits[strtolower(ltrim($trait, '\\'))] = true;
            }
        }

        return $traits;
    }

    /**
     * @param MethodRecord $method Method being judged
     * @return bool True when the method is a constructor
     */
    private function isConstructor(MethodRecord $method): bool
    {
        return strcasecmp($method->name, '__construct') === 0;
    }

    /**
     * @param ClassRecord $declaring Class, interface or trait the tag is written in
     * @param MethodRecord $method Method carrying the tag
     * @param string $exception Exception class the tag names
     * @return Violation Hit on the line of the declaration, the docblock standing directly above it
     */
    private function violation(ClassRecord $declaring, MethodRecord $method, string $exception): Violation
    {
        return new Violation(
            self::ID,
            $declaring->path,
            $method->line,
            sprintf(
                '%s::%s() documents %s its body cannot throw',
                $this->shortName($method->class),
                $method->name,
                $this->shortName($exception),
            ),
        );
    }

    /**
     * @param string $name Fully qualified class name
     * @return string The name without its namespace
     */
    private function shortName(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}

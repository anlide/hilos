<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces the `@throws` propagation rule of phpdoc.md, the one rule in this guide
 * that nothing pushed back on while it was being broken: PHP has no checked
 * exceptions, the IDE stays quiet and the tests stay green.
 *
 * Two halves under one id, because they are the same contract read in the two
 * directions it can be broken in:
 *
 * - **A call does not propagate.** An exception the target documents reaches the
 *   caller, no enclosing `catch` swallows it, and the caller's own `@throws` names
 *   neither it nor an ancestor of it. The hit sits on the line of the call, which is
 *   where a reader can see what was called, rather than on the docblock.
 * - **An implementation is wider than the contract.** A method documents an exception
 *   the declaration it overrides does not, so everyone reading through the interface
 *   is sure the call is safe. That was the actual defect this rule was written for.
 *
 * A private helper is walked through rather than trusted: phpdoc.md asks for no
 * `@throws` on one unless it carries a local contract, so a tag there is not an
 * answer and its absence is not permission. The rule takes the exceptions out of the
 * helper's body and asks them of the public caller, naming the whole chain in the
 * report.
 *
 * The rule is deliberately narrower than its document — see {@see self::SCOPE} — and
 * never wider: a call whose target is not written down anywhere is left alone. A
 * constant that names a magic property's class is a type written down on the class
 * itself, and so is a class-level `@property-read` or `@property` tag naming it, so
 * resolving either does not widen that boundary.
 */
final class ThrowsPropagationRule implements CrossFileRule
{
    public const string ID = 'THROWS-PROPAGATION';

    /**
     * What the rule judges, said out loud because a green run must not be read as
     * "the contracts agree". It is repeated in the guard's failure message for the
     * same reason.
     */
    public const string SCOPE = 'THROWS-PROPAGATION judges only calls whose target is known without inferring a'
        . ' type — $this->, self::, static::, parent::, new, a call by class name, a parameter, property or loop'
        . ' variable with a declared type, an index on any of those, which stands for the ArrayAccess method behind'
        . ' the brackets, a magic property whose class the class it is read on names in a constant, and one whose'
        . ' type that class writes down in a class-level @property-read or @property tag, the nearest such record'
        . ' winning. A member reached through __get() and named nowhere, and a vendor class, are outside it by'
        . ' declaration, not for want of debt:';

    private const string DOC = 'docs/agents/code-style/phpdoc.md';

    private SourceIndex $index;

    private BodyExceptions $body;

    private function __construct()
    {
    }

    /**
     * The only constructor there is. The rule was phased in behind a judged zone that
     * grew one subsystem at a time while the index stayed whole — turned on across
     * every root at once it reported 779 lines in 234 files, and a baseline that size
     * is read as a mute list rather than as owed work. The phases are over: what is
     * left outside the index is left outside by the index, not by the rule.
     *
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
     * @return iterable<Violation> One entry per exception a method owes, ordered by file and line
     */
    public function check(SourceIndex $index): iterable
    {
        $this->index = $index;
        $this->body = BodyExceptions::forContracts($index);

        $violations = [];
        foreach ($index->classes() as $class) {
            foreach ($class->methods as $method) {
                $violations = [
                    ...$violations,
                    ...$this->judgeBody($class, $method),
                    ...$this->judgeAgainstContract($class, $method),
                ];
            }
            // A trait can satisfy an interface without the using class naming the method
            // anywhere, and that is still the shape this rule was written for.
            foreach ($index->traitMethods($class) as $provided) {
                $violations = [...$violations, ...$this->judgeAgainstContract($class, $provided)];
            }
        }

        yield from $this->ordered($violations);
    }

    /**
     * One trait used by two classes that implement the same interface reaches the same
     * line twice, and the report says a thing once. Deduplication is by the hit and not
     * by the class it was found from, because the two differ: a trait's widening is
     * reported where its tag is written rather than where it is used.
     *
     * @param array<int, Violation> $violations Hits collected across the tree
     * @return array<int, Violation> The same hits, deduplicated and ordered by file and line
     */
    private function ordered(array $violations): array
    {
        $unique = [];
        foreach ($violations as $violation) {
            $unique[$violation->relativePath . ':' . $violation->line . ' ' . $violation->message] = $violation;
        }

        $ordered = array_values($unique);
        usort($ordered, static fn(Violation $left, Violation $right): int
            => [$left->relativePath, $left->line, $left->message]
                <=> [$right->relativePath, $right->line, $right->message]);

        return $ordered;
    }

    /**
     * @param ClassRecord $class Class being judged
     * @param MethodRecord $method Method being judged
     * @return array<int, Violation> One entry per exception the body lets through undeclared
     */
    private function judgeBody(ClassRecord $class, MethodRecord $method): array
    {
        if ($method->isPrivateLink()) {
            return [];
        }

        $violations = [];
        foreach ($method->callSites as $site) {
            foreach ($this->body->reaching($class, $method, $site) as $exception => $source) {
                if ($this->body->covers($method->throws, $exception)) {
                    continue;
                }
                $violations[] = new Violation(
                    self::ID,
                    $class->path,
                    $site->line,
                    $this->describeGap($class, $method, $exception, $source),
                );
            }
        }

        return $violations;
    }

    /**
     * @param ClassRecord $class Class being judged
     * @param MethodRecord $method Method being judged
     * @return array<int, Violation> One entry per exception documented above the inherited contract
     */
    private function judgeAgainstContract(ClassRecord $class, MethodRecord $method): array
    {
        if ($method->isPrivateLink() || $method->throws === [] || $this->isConstructor($method)) {
            return [];
        }

        $contract = $this->index->resolveInheritedContract($class, $method->name);
        if ($contract === null) {
            return [];
        }

        // A trait-provided method is judged in the using class but reported where its tag
        // is written, which is where the fix goes.
        $declaring = $this->index->find($method->class);
        $violations = [];
        foreach (array_unique($method->throws) as $exception) {
            if ($this->body->covers($contract->throws, $exception)) {
                continue;
            }
            $violations[] = new Violation(
                self::ID,
                $declaring === null ? $class->path : $declaring->path,
                $method->line,
                sprintf(
                    '%s documents %s that %s does not declare',
                    $this->label($method->class, $method->name),
                    $this->shortName($exception),
                    $this->label($contract->class, $contract->name),
                ),
            );
        }

        return $violations;
    }

    /**
     * PHP exempts the constructor from the override contract, and so must the rule: a
     * subclass constructor is never reached through the base one, so documenting what
     * it throws widens nothing. Demanding the tag on the base would put there a
     * promise that is not true of the base.
     *
     * The first half still judges `new Bar()` against `Bar::__construct()`, which is
     * the contract that call really does reach.
     *
     * @param MethodRecord $method Method being judged
     * @return bool True when the method is a constructor
     */
    private function isConstructor(MethodRecord $method): bool
    {
        return strcasecmp($method->name, '__construct') === 0;
    }

    /**
     * @param ClassRecord $class Class the call is written in
     * @param MethodRecord $method Method that owes the exception
     * @param string $exception Fully qualified exception class
     * @param array{origin: ?string, chain: array<int, string>} $source Where the exception comes from
     * @return string Report message, naming the whole chain when a private link is on it
     */
    private function describeGap(ClassRecord $class, MethodRecord $method, string $exception, array $source): string
    {
        $caller = $this->label($class->name, $method->name);
        $short = $this->shortName($exception);
        if ($source['chain'] !== []) {
            $chain = implode(' -> ', [$caller, ...$source['chain'], $short]);

            return sprintf('%s does not propagate %s: %s', $caller, $short, $chain);
        }
        if ($source['origin'] === null) {
            return sprintf('%s does not document %s it throws', $caller, $short);
        }

        return sprintf('%s does not propagate %s documented on %s', $caller, $short, $source['origin']);
    }

    /**
     * @param string $class Fully qualified class name
     * @param string $method Method name
     * @return string How a report names the method
     */
    private function label(string $class, string $method): string
    {
        return $this->shortName($class) . '::' . $method . '()';
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

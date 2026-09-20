<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

/**
 * What a method body lets out, read once for every rule that judges `@throws`.
 *
 * The first two directions of the contract ask which exceptions reach a caller; the
 * third asks whether a documented one can reach it at all. Both answers rest on the
 * same walk — resolve each call, take the target's contract, walk through a private
 * helper instead of trusting its tag, subtract what an enclosing `catch` swallows —
 * and two copies of it would drift the first time the resolver learns a new form.
 * A magic read contributes its reader's contract to the third direction alone;
 * the first two directions do not demand that contract from callers.
 *
 * The walk answers a second question on the way, which only the third direction
 * needs: whether every call in the body resolved into a declaration. A claim that a
 * body cannot throw something is honest only when nothing in it was skipped.
 */
final class BodyExceptions
{
    /** Prefix the report puts on a link the walk went through instead of trusting. */
    private const string PRIVATE_MARK = 'private ';

    private CallResolver $resolver;

    private ExceptionHierarchy $hierarchy;

    /** @var array<string, array<string, array<int, string>>> Exceptions reachable through a private link, by method key */
    private array $throughPrivateMemo = [];

    /** @var array<string, bool> Whether every call of a private link resolved, by method key */
    private array $resolvedMemo = [];

    /**
     * @param SourceIndex $index Indexed tree the calls are resolved against
     * @param bool $magicReads Whether magic readers contribute their contracts
     */
    private function __construct(private readonly SourceIndex $index, private readonly bool $magicReads)
    {
        $this->resolver = new CallResolver($index);
        $this->hierarchy = new ExceptionHierarchy($index);
    }

    /**
     * @param SourceIndex $index Indexed tree the calls are resolved against
     * @return self Walk of explicit contracts for the first two directions
     */
    public static function forContracts(SourceIndex $index): self
    {
        return new self($index, false);
    }

    /**
     * @param SourceIndex $index Indexed tree the calls are resolved against
     * @return self Walk including magic readers for the orphaned-tag direction
     */
    public static function withMagicReads(SourceIndex $index): self
    {
        return new self($index, true);
    }

    /**
     * @param ClassRecord $class Class the call is written in
     * @param MethodRecord $method Method the call is written in
     * @param CallSite $site Call being judged
     * @return array<string, array{origin: ?string, chain: array<int, string>}> Where each surviving exception comes from
     */
    public function reaching(ClassRecord $class, MethodRecord $method, CallSite $site): array
    {
        if ($site->kind === CallSite::KIND_THROW) {
            $reaching = [$site->target => ['origin' => null, 'chain' => []]];
        } else {
            $target = $this->resolver->resolve($class, $method, $site);
            $reaching = $target === null ? [] : $this->contractOf($target);
        }

        if ($this->magicReads) {
            foreach ($this->resolver->magicReaders($class, $method, $site) ?? [] as $reader) {
                $reaching += $this->contractOf($reader);
            }
        }

        foreach (array_keys($reaching) as $exception) {
            if ($this->covers($site->caught, (string)$exception)) {
                unset($reaching[$exception]);
            }
        }

        return $reaching;
    }

    /**
     * @param array<int, string> $declared Exception classes named by a `@throws` or caught by a `catch`
     * @param string $exception Fully qualified exception class that reaches the caller
     * @return bool True when one of the declared classes is a truthful answer for it
     */
    public function covers(array $declared, string $exception): bool
    {
        foreach ($declared as $candidate) {
            if ($this->hierarchy->covers($candidate, $exception)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A private helper is walked through here exactly as {@see self::reaching()} walks
     * through it: a method whose helper carries one unresolved call is as unresolved as
     * one carrying it directly.
     *
     * @param ClassRecord $class Class the method is judged in
     * @param MethodRecord $method Method whose body is read
     * @return bool True when every call in the body, and in every private helper it reaches, resolved into a declaration
     */
    public function everyCallResolved(ClassRecord $class, MethodRecord $method): bool
    {
        return $this->sitesResolved($class, $method, []);
    }

    /**
     * @param ClassRecord $class Class the calls are resolved against
     * @param MethodRecord $method Method whose body is read
     * @param array<int, string> $seen Private links already on the stack, guarding a cycle
     * @return bool True when nothing on the way was left unresolved
     */
    private function sitesResolved(ClassRecord $class, MethodRecord $method, array $seen): bool
    {
        foreach ($method->callSites as $site) {
            if (!$this->resolver->resolves($class, $method, $site)) {
                return false;
            }
            $target = $this->resolver->resolve($class, $method, $site);
            if ($target !== null && $target->isPrivateLink() && !$this->privateLinkResolved($target, $seen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A private helper resolves against the class that declares it, the way
     * {@see self::throughPrivateLink()} reads it, and is remembered the same way: only
     * when reached from the top, where no link above it can still turn out unresolved.
     *
     * @param MethodRecord $target Private method to walk through
     * @param array<int, string> $seen Method keys already on the stack
     * @return bool True when every call in the helper, and in the helpers below it, resolved
     */
    private function privateLinkResolved(MethodRecord $target, array $seen): bool
    {
        $key = strtolower($target->class . '::' . $target->name);
        if (in_array($key, $seen, true)) {
            return true;
        }
        if ($seen === [] && isset($this->resolvedMemo[$key])) {
            return $this->resolvedMemo[$key];
        }

        $class = $this->index->find($target->class);
        $resolved = $class !== null && $this->sitesResolved($class, $target, [...$seen, $key]);
        if ($seen === []) {
            $this->resolvedMemo[$key] = $resolved;
        }

        return $resolved;
    }

    /**
     * A contract is what a method declares — unless it is private, in which case there
     * is no contract and the walk reads the body instead.
     *
     * @param MethodRecord $target Declaration the call reaches
     * @return array<string, array{origin: ?string, chain: array<int, string>}> Where each exception comes from
     */
    private function contractOf(MethodRecord $target): array
    {
        if (!$target->isPrivateLink()) {
            $origin = $this->label($target->class, $target->name);
            $contract = [];
            foreach (array_unique($target->throws) as $exception) {
                $contract[$exception] = ['origin' => $origin, 'chain' => []];
            }

            return $contract;
        }

        $reached = [];
        foreach ($this->throughPrivateLink($target, []) as $exception => $chain) {
            $reached[$exception] = [
                'origin' => null,
                'chain' => [self::PRIVATE_MARK . $this->label($target->class, $target->name), ...$chain],
            ];
        }

        return $reached;
    }

    /**
     * Walks the body of a private helper, and of every private helper it calls, for the
     * exceptions that leave it. Its own `@throws` is not read: phpdoc.md asks for none
     * on a private helper, so reading one would let a chain be cut by a tag the document
     * discourages.
     *
     * @param MethodRecord $target Private method to walk through
     * @param array<int, string> $seen Method keys already on the stack, guarding a cycle
     * @return array<string, array<int, string>> Chain of further private links, by exception
     */
    private function throughPrivateLink(MethodRecord $target, array $seen): array
    {
        $key = strtolower($target->class . '::' . $target->name);
        if (in_array($key, $seen, true)) {
            return [];
        }
        if ($seen === [] && isset($this->throughPrivateMemo[$key])) {
            return $this->throughPrivateMemo[$key];
        }

        $class = $this->index->find($target->class);
        if ($class === null) {
            return [];
        }

        $reached = [];
        foreach ($target->callSites as $site) {
            foreach ($this->throughOneSite($class, $target, $site, [...$seen, $key]) as $exception => $chain) {
                if (!$this->covers($site->caught, (string)$exception)) {
                    $reached[$exception] = $chain;
                }
            }
        }

        if ($seen === []) {
            $this->throughPrivateMemo[$key] = $reached;
        }

        return $reached;
    }

    /**
     * @param ClassRecord $class Class the private helper lives in
     * @param MethodRecord $method The private helper itself
     * @param CallSite $site One call inside it
     * @param array<int, string> $seen Method keys already on the stack
     * @return array<string, array<int, string>> Chain of further private links, by exception
     */
    private function throughOneSite(ClassRecord $class, MethodRecord $method, CallSite $site, array $seen): array
    {
        if ($site->kind === CallSite::KIND_THROW) {
            return [$site->target => []];
        }

        $reached = [];
        if ($this->magicReads) {
            foreach ($this->resolver->magicReaders($class, $method, $site) ?? [] as $reader) {
                $reached += array_fill_keys(array_unique($reader->throws), []);
            }
        }

        $target = $this->resolver->resolve($class, $method, $site);
        if ($target === null) {
            return $reached;
        }
        if (!$target->isPrivateLink()) {
            return $reached + array_fill_keys(array_unique($target->throws), []);
        }

        $label = self::PRIVATE_MARK . $this->label($target->class, $target->name);
        foreach ($this->throughPrivateLink($target, $seen) as $exception => $chain) {
            $reached[$exception] = [$label, ...$chain];
        }

        return $reached;
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

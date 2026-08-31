<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Throws;

use Hilos\Tests\CodeStyle\SourceScanner;

/**
 * One tokenization pass over the production roots, kept as the little the cross-file
 * rules need: which classes exist, where they stand, whether they are abstract, what
 * they extend, use and implement, which constants and methods they declare with which
 * visibility and `@throws`, and where inside each body a call or a `throw` sits.
 *
 * The index is always built over every production root, whatever zone is judged: a
 * demo calls the framework, and an index cut down to the judged zone would answer
 * "no contract" for half the calls in it — a silent pass instead of a hit.
 *
 * It reads tokens rather than a loaded tree on purpose. Loading the sources would
 * mean running them, and the suite has to be able to index a file that does not
 * even autoload; a name written inside a string or a comment is not a declaration,
 * which is the other half of why raw text is not read either.
 */
final class SourceIndex
{
    /** Type names that never name a class, so a parameter or property carrying one is out of scope. */
    private const array RESERVED_TYPES = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never',
        'null', 'object', 'parent', 'self', 'static', 'string', 'true', 'void',
    ];

    /** Token types that open a brace pair; string interpolation opens one without a plain `{`. */
    private const array BRACE_OPENERS = ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

    /** Marks a loop variable two loops bind to different receivers; dropped before the record is built. */
    private const array AMBIGUOUS_BINDING = ['base' => '', 'path' => []];

    /** @var array<string, ClassRecord> Indexed classes keyed by lowercased fully qualified name */
    private array $classes = [];

    /** @var array<int, array{0: int|string, 1: string, 2: int}> Significant tokens of the file being read */
    private array $tokens = [];

    /** Position in {@see self::$tokens} of the walk currently consuming the file. */
    private int $cursor = 0;

    /** Namespace of the file being read, empty in the global one. */
    private string $namespace = '';

    /** @var array<string, string> Imports of the file being read: fully qualified name by lowercased short name */
    private array $aliases = [];

    /** @var array<string, string> Properties promoted by the constructor read last */
    private array $promoted = [];

    /** Fully qualified name of the class whose body is being read, for `self` and `static`. */
    private string $currentClass = '';

    /** Fully qualified parent of that class, for `parent`. */
    private ?string $currentParent = null;

    /** Path of the file being read, addressed from the base the index was built over. */
    private string $path = '';

    /**
     * @param string $base Absolute path the roots are relative to
     * @param array<int, string> $roots Source roots to index, each relative to the base
     * @return self Index of every PHP file under those roots
     */
    public static function forRoots(string $base, array $roots): self
    {
        $index = new self();
        foreach ($roots as $root) {
            $scanner = new SourceScanner($base . '/' . $root);
            foreach ($scanner->files() as $file) {
                $index->readFile(
                    $root . '/' . $scanner->relativePath($file),
                    (string)file_get_contents($file->getPathname()),
                );
            }
        }

        return $index;
    }

    /**
     * @return array<string, ClassRecord> Every indexed class keyed by lowercased fully qualified name
     */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * @param string $name Fully qualified class name, with or without a leading backslash
     * @return ?ClassRecord The class, or null when no scanned root declares it
     */
    public function find(string $name): ?ClassRecord
    {
        return $this->classes[strtolower(ltrim($name, '\\'))] ?? null;
    }

    /**
     * Walks up the declaration links the way PHP resolves a call: the class itself,
     * then its traits, then its parents, then its interfaces. The first contract
     * found wins, and an abstract or interface method is a contract in full — it has
     * no body, so its tags are all there is.
     *
     * @param string $class Fully qualified name of the receiver's class
     * @param string $method Method name as written at the call site
     * @return ?MethodRecord The declaration a call would reach, or null when nothing declares it
     */
    public function resolveMethod(string $class, string $method): ?MethodRecord
    {
        return $this->lookupMethod($class, strtolower($method), []);
    }

    /**
     * The declaration a method overrides, which is the contract it may not widen.
     * Traits are left out: using a trait is not overriding, and a class declaring a
     * method of the same name simply wins over it.
     *
     * @param ClassRecord $class Class that declares the override
     * @param string $method Method name as written
     * @return ?MethodRecord The inherited declaration, or null when the method overrides nothing
     */
    public function resolveInheritedContract(ClassRecord $class, string $method): ?MethodRecord
    {
        $name = strtolower($method);
        foreach ($this->contractSources($class) as $source) {
            $found = $this->lookupContract($source, $name, []);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Walks the parent chain the way an inherited constant is resolved: the class
     * itself first, then its parents, the nearest declaration winning. Interfaces and
     * traits are left out — a constant reached through one is not the inheritance a
     * declaration on a base class is written for, and reading it as one would let a
     * shared interface answer for classes that never declared anything.
     *
     * @param string $class Fully qualified name of the class the constant is read on
     * @param string $constant Constant name as written
     * @return ?string Raw value text of the declaration that wins, or null when nothing in the chain declares it
     */
    public function resolveConstant(string $class, string $constant): ?string
    {
        $seen = [];
        $current = $this->find($class);
        while ($current !== null && !isset($seen[strtolower($current->name)])) {
            if (isset($current->constants[$constant])) {
                return $current->constants[$constant];
            }
            $seen[strtolower($current->name)] = true;
            $current = $current->parent === null ? null : $this->find($current->parent);
        }

        return null;
    }

    /**
     * @param string $path File path, addressed from the base the index is built over
     * @param string $source File contents
     */
    private function readFile(string $path, string $source): void
    {
        $this->path = $path;
        $this->tokens = $this->significantTokens(token_get_all($source));
        $this->namespace = '';
        $this->aliases = [];
        $this->cursor = 0;

        while (isset($this->tokens[$this->cursor])) {
            $type = $this->tokens[$this->cursor][0];
            if ($type === T_NAMESPACE && $this->isName($this->tokens[$this->cursor + 1] ?? null)) {
                $this->namespace = ltrim($this->tokens[$this->cursor + 1][1], '\\');
                $this->cursor += 2;
                continue;
            }
            if ($type === T_USE) {
                $this->readImports();
                continue;
            }
            if ($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT || $type === T_ENUM) {
                $this->readDeclaration($type);
                continue;
            }
            $this->cursor++;
        }
    }

    /**
     * Drops whitespace and line comments and gives every token a line, including the
     * single-character ones PHP hands back as bare strings.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, array{0: int|string, 1: string, 2: int}> Significant tokens, each carrying its line
     */
    private function significantTokens(array $tokens): array
    {
        $significant = [];
        $line = 1;
        foreach ($tokens as $token) {
            $type = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $at = is_array($token) ? $token[2] : $line;
            $line = $at + substr_count($text, "\n");
            if ($type === T_WHITESPACE || $type === T_COMMENT) {
                continue;
            }
            $significant[] = [$type, $text, $at];
        }

        return $significant;
    }

    /**
     * Reads one `use` statement into the alias table. Function and constant imports
     * are skipped: they name no class, and the rule resolves classes only.
     */
    private function readImports(): void
    {
        $this->cursor++;
        $type = $this->tokens[$this->cursor][0] ?? null;
        if ($type === T_FUNCTION || $type === T_CONST) {
            $this->skipTo(';');

            return;
        }

        $prefix = '';
        while (isset($this->tokens[$this->cursor])) {
            $token = $this->tokens[$this->cursor];
            if ($token[0] === ';') {
                $this->cursor++;

                return;
            }
            if (!$this->isName($token)) {
                $this->cursor++;
                continue;
            }

            $name = ltrim($token[1], '\\');
            $this->cursor++;
            // A group import spells its prefix as a qualified name plus a trailing separator.
            if (($this->tokens[$this->cursor][0] ?? null) === T_NS_SEPARATOR) {
                $this->cursor++;
            }
            if (($this->tokens[$this->cursor][0] ?? null) === '{') {
                $prefix = rtrim($name, '\\') . '\\';
                $this->cursor++;
                continue;
            }
            $this->registerImport($prefix . $name);
        }
    }

    /**
     * @param string $name Fully qualified name of the imported class, `as` alias not yet read
     */
    private function registerImport(string $name): void
    {
        $alias = null;
        if (($this->tokens[$this->cursor][0] ?? null) === T_AS) {
            $alias = $this->tokens[$this->cursor + 1][1] ?? null;
            $this->cursor += 2;
        }

        $separator = strrpos($name, '\\');
        $short = $alias ?? ($separator === false ? $name : substr($name, $separator + 1));
        $this->aliases[strtolower($short)] = $name;
    }

    /**
     * @param int $keyword Token type of the declaration keyword
     */
    private function readDeclaration(int $keyword): void
    {
        $previous = $this->tokens[$this->cursor - 1][0] ?? null;
        if ($previous === T_DOUBLE_COLON) {
            $this->cursor++;

            return;
        }
        if ($previous === T_NEW) {
            $this->cursor = $this->skipAnonymousClass($this->cursor);

            return;
        }

        $this->cursor++;
        $nameToken = $this->tokens[$this->cursor] ?? null;
        if (!$this->isName($nameToken)) {
            return;
        }

        $name = $this->namespace === '' ? $nameToken[1] : $this->namespace . '\\' . $nameToken[1];
        $isAbstract = $this->declaredAbstract($this->cursor - 2);
        $line = $nameToken[2];
        $this->cursor++;
        [$parent, $interfaces] = $this->readInheritance($keyword === T_INTERFACE);
        if (($this->tokens[$this->cursor][0] ?? null) !== '{') {
            return;
        }

        $this->readBody($name, $parent, $interfaces, $isAbstract, $line);
    }

    /**
     * The modifiers of a class stand in front of its keyword and may be written in
     * either order (`abstract readonly class`, `readonly abstract class`), so the walk
     * goes backwards over the whole run of them rather than reading the one token
     * before the keyword.
     *
     * @param int $cursor Index of the token directly before the declaration keyword
     * @return bool True when `abstract` stands among the modifiers
     */
    private function declaredAbstract(int $cursor): bool
    {
        for (; $cursor >= 0; $cursor--) {
            $type = $this->tokens[$cursor][0];
            if ($type === T_ABSTRACT) {
                return true;
            }
            if ($type !== T_FINAL && $type !== T_READONLY) {
                return false;
            }
        }

        return false;
    }

    /**
     * An interface extends interfaces and a class extends one parent, so the same
     * keyword lands in different places depending on what is being declared.
     *
     * @param bool $isInterface True when the declaration being read is an interface
     * @return array{0: ?string, 1: array<int, string>} Parent class and implemented interfaces
     */
    private function readInheritance(bool $isInterface): array
    {
        $parent = null;
        $interfaces = [];
        while (isset($this->tokens[$this->cursor]) && $this->tokens[$this->cursor][0] !== '{') {
            $type = $this->tokens[$this->cursor][0];
            if ($type === T_EXTENDS) {
                $this->cursor++;
                $names = $this->readNameList();
                if ($isInterface) {
                    $interfaces = [...$interfaces, ...$names];
                    continue;
                }
                $parent = $names[0] ?? null;
                continue;
            }
            if ($type === T_IMPLEMENTS) {
                $this->cursor++;
                $interfaces = [...$interfaces, ...$this->readNameList()];
                continue;
            }
            $this->cursor++;
        }

        return [$parent, $interfaces];
    }

    /**
     * @return array<int, string> Fully qualified names of a comma-separated list
     */
    private function readNameList(): array
    {
        $names = [];
        while (isset($this->tokens[$this->cursor])) {
            $token = $this->tokens[$this->cursor];
            if ($this->isName($token)) {
                $names[] = $this->resolveName($token[1]);
                $this->cursor++;
                continue;
            }
            if ($token[0] === ',') {
                $this->cursor++;
                continue;
            }
            break;
        }

        return $names;
    }

    /**
     * @param string $name Fully qualified name of the class being declared
     * @param ?string $parent Fully qualified parent class
     * @param array<int, string> $interfaces Fully qualified interfaces
     * @param bool $isAbstract True when the declaration carries the `abstract` modifier
     * @param int $line Line the declaration sits on
     */
    private function readBody(string $name, ?string $parent, array $interfaces, bool $isAbstract, int $line): void
    {
        $this->cursor++;
        $this->currentClass = $name;
        $this->currentParent = $parent;
        $traits = [];
        $methods = [];
        $properties = [];
        $constants = [];
        $doc = null;
        $modifiers = [];
        $typeTokens = [];

        while (isset($this->tokens[$this->cursor])) {
            $token = $this->tokens[$this->cursor];
            $type = $token[0];
            if ($type === '}') {
                $this->cursor++;
                break;
            }
            if ($type === T_DOC_COMMENT) {
                $doc = $token[1];
                $this->cursor++;
                continue;
            }
            if ($this->isModifier($type)) {
                $modifiers[] = $type;
                $this->cursor++;
                continue;
            }
            if ($type === T_USE) {
                $this->cursor++;
                $traits = [...$traits, ...$this->readNameList()];
                $this->skipTraitAdaptations();
                [$doc, $modifiers, $typeTokens] = [null, [], []];
                continue;
            }
            if ($type === T_CONST) {
                $constants = [...$constants, ...$this->readConstants()];
                [$doc, $modifiers, $typeTokens] = [null, [], []];
                continue;
            }
            if ($type === T_CASE) {
                $this->skipMember();
                [$doc, $modifiers, $typeTokens] = [null, [], []];
                continue;
            }
            if ($type === T_ATTRIBUTE) {
                $this->cursor = $this->skipAttribute($this->cursor);
                continue;
            }
            if ($type === T_FUNCTION) {
                $method = $this->readMethod($name, $doc, $modifiers);
                if ($method !== null) {
                    $methods[strtolower($method->name)] = $method;
                    $properties = [...$properties, ...$this->promoted];
                }
                [$doc, $modifiers, $typeTokens] = [null, [], []];
                continue;
            }
            if ($type === T_VARIABLE) {
                $declared = $this->declaredType($typeTokens, $doc, 'var');
                if ($declared !== null) {
                    $property = ltrim($token[1], '$');
                    $properties[$this->propertyKey($property, in_array(T_STATIC, $modifiers, true))] = $declared;
                }
                $this->skipMember();
                [$doc, $modifiers, $typeTokens] = [null, [], []];
                continue;
            }
            if (in_array($type, self::BRACE_OPENERS, true)) {
                $this->cursor = $this->matchingBrace($this->cursor) + 1;
                continue;
            }

            $typeTokens[] = $token;
            $this->cursor++;
        }

        $this->classes[strtolower($name)] = new ClassRecord(
            $name,
            $this->path,
            $parent,
            $interfaces,
            $traits,
            $methods,
            $properties,
            $constants,
            $isAbstract,
            $line,
        );
    }

    /**
     * Reads one `const` statement into raw value text by name, keeping the text as
     * written rather than evaluating it: unevaluated it still tells an enum case from
     * an empty list, and evaluating would mean loading sources the index deliberately
     * only tokenizes.
     *
     * Everything in front of the `=` is the optional type and the name, so the name is
     * whatever name token stands directly before it — which is also how one statement
     * declaring several constants is read, one comma-separated part at a time.
     *
     * @return array<string, string> Raw value text by constant name
     */
    private function readConstants(): array
    {
        $this->cursor++;
        $constants = [];
        $declared = null;
        $name = null;
        $value = '';
        while (isset($this->tokens[$this->cursor])) {
            $token = $this->tokens[$this->cursor];
            $type = $token[0];
            if ($type === '}') {
                break;
            }
            if ($type === ';') {
                $this->cursor++;
                break;
            }
            if ($name === null) {
                if ($type === '=') {
                    $name = $declared;
                }
                if ($this->isName($token)) {
                    $declared = $token[1];
                }
                $this->cursor++;
                continue;
            }
            if ($type === ',') {
                $constants[$name] = $value;
                [$declared, $name, $value] = [null, null, ''];
                $this->cursor++;
                continue;
            }
            if ($type === '(' || $type === '[') {
                $close = $this->matchingBracket($this->cursor, $type, $type === '(' ? ')' : ']');
                for (; $this->cursor <= $close; $this->cursor++) {
                    $value .= $this->tokens[$this->cursor][1];
                }
                continue;
            }
            $value .= $token[1];
            $this->cursor++;
        }

        if ($name !== null) {
            $constants[$name] = $value;
        }

        return $constants;
    }

    /**
     * @param string $class Fully qualified name of the declaring class
     * @param ?string $doc Docblock directly above the declaration
     * @param array<int, int|string> $modifiers Modifier token types read before the keyword
     * @return ?MethodRecord The method, or null when the keyword opened a closure rather than a declaration
     */
    private function readMethod(string $class, ?string $doc, array $modifiers): ?MethodRecord
    {
        $this->promoted = [];
        $this->cursor++;
        // The by-reference marker is its own token type since PHP 8.1, so it is read by its text.
        if (($this->tokens[$this->cursor][1] ?? null) === '&') {
            $this->cursor++;
        }
        $nameToken = $this->tokens[$this->cursor] ?? null;
        if (!$this->isMemberName($nameToken) || ($this->tokens[$this->cursor + 1][0] ?? null) !== '(') {
            return null;
        }

        $name = $nameToken[1];
        $line = $nameToken[2];
        $this->cursor++;
        $variableTypes = $this->readParameters($doc);
        while (isset($this->tokens[$this->cursor])) {
            $type = $this->tokens[$this->cursor][0];
            if ($type === '{' || $type === ';') {
                break;
            }
            $this->cursor++;
        }

        $hasBody = ($this->tokens[$this->cursor][0] ?? null) === '{';
        $callSites = [];
        $arrayBindings = [];
        if ($hasBody) {
            [$callSites, $arrayBindings] = $this->readMethodBody($class);
        } else {
            $this->cursor++;
        }

        return new MethodRecord(
            $class,
            $name,
            $this->visibilityOf($modifiers),
            $hasBody,
            $line,
            $this->throwsTags($doc),
            $callSites,
            $variableTypes,
            $arrayBindings,
        );
    }

    /**
     * Reads the parameter list, taking the declared type of each parameter and, for a
     * promoted one, recording it as a property of the class as well.
     *
     * @param ?string $doc Docblock of the method, read for `@param` types the signature cannot carry
     * @return array<string, string> Declared type by variable name, an `[]` suffix marking an array of it
     */
    private function readParameters(?string $doc): array
    {
        if (($this->tokens[$this->cursor][0] ?? null) !== '(') {
            return [];
        }

        $documented = $this->paramTags($doc);
        $types = [];
        $typeTokens = [];
        $isPromoted = false;
        $seenVariable = false;
        $depth = 0;
        while (isset($this->tokens[$this->cursor])) {
            $token = $this->tokens[$this->cursor];
            $type = $token[0];
            // T_ATTRIBUTE is `#[`: one token that opens a bracket a plain `]` will close.
            if ($type === '(' || $type === '[' || $type === T_ATTRIBUTE || in_array($type, self::BRACE_OPENERS, true)) {
                $depth++;
                $this->cursor++;
                continue;
            }
            if ($type === ')' || $type === ']' || $type === '}') {
                $depth--;
                $this->cursor++;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 1) {
                $this->cursor++;
                continue;
            }
            if ($type === ',') {
                [$typeTokens, $isPromoted, $seenVariable] = [[], false, false];
                $this->cursor++;
                continue;
            }
            if ($this->isVisibility($type) || $type === T_READONLY) {
                $isPromoted = true;
                $this->cursor++;
                continue;
            }
            if ($type === T_VARIABLE && !$seenVariable) {
                $seenVariable = true;
                $declared = $this->typeFromTokens($typeTokens) ?? ($documented[$token[1]] ?? null);
                if ($declared !== null) {
                    $types[$token[1]] = $declared;
                    if ($isPromoted) {
                        $this->promoted[ltrim($token[1], '$')] = $declared;
                    }
                }
                $this->cursor++;
                continue;
            }
            if (!$seenVariable) {
                $typeTokens[] = $token;
            }
            $this->cursor++;
        }

        return $types;
    }

    /**
     * Walks a method body for the places an exception can enter it, leaving the body
     * of a closure, an arrow function and an anonymous class out: an exception raised
     * there reaches whoever calls the callback, not the method that wrote it.
     *
     * @param string $class Fully qualified name of the declaring class
     * @return array{0: array<int, CallSite>, 1: array<string, array{base: string, path: array<int, string>}>}
     */
    private function readMethodBody(string $class): array
    {
        $start = $this->cursor;
        $end = $this->matchingBrace($start);
        $catches = $this->readCatchRanges($start, $end);
        $sites = [];
        $bindings = [];
        $assigned = [];
        $cursor = $start + 1;

        while ($cursor < $end) {
            $token = $this->tokens[$cursor];
            $type = $token[0];
            if ($type === T_FUNCTION || $type === T_FN) {
                $cursor = $type === T_FUNCTION ? $this->skipClosure($cursor) : $this->skipArrowFunction($cursor);
                continue;
            }
            if ($type === T_NEW && ($this->tokens[$cursor + 1][0] ?? null) === T_CLASS) {
                $cursor = $this->skipAnonymousClass($cursor + 1);
                continue;
            }
            if ($type === T_FOREACH) {
                $bindings = $this->mergeBinding($bindings, $this->readForeachBinding($cursor));
                $cursor++;
                continue;
            }
            if ($type === T_VARIABLE && ($this->tokens[$cursor + 1][0] ?? null) === '=') {
                $assigned[$token[1]] = true;
            }
            if ($type === T_THROW) {
                $raised = $this->raisedException($cursor, $class);
                if ($raised !== null) {
                    $sites[] = new CallSite(
                        CallSite::KIND_THROW,
                        $token[2],
                        '',
                        [],
                        $raised,
                        $this->caughtAt($catches, $cursor),
                    );
                }
                $cursor++;
                continue;
            }
            if ($type === T_NEW) {
                $constructed = $this->raisedException($cursor - 1, $class);
                if ($constructed !== null) {
                    $sites[] = new CallSite(
                        CallSite::KIND_NEW,
                        $token[2],
                        '',
                        [],
                        $constructed,
                        $this->caughtAt($catches, $cursor),
                    );
                }
                $cursor += 2;
                continue;
            }

            [$site, , $next] = $this->readChain($cursor, $end);
            if ($site !== null) {
                $sites[] = $site->withCaught($this->caughtAt($catches, $cursor));
            }
            $cursor = max($next, $cursor + 1);
        }

        $this->cursor = $end + 1;
        // A loop variable the body also assigns to holds two things at two places, and
        // the index carries no token ranges to tell them apart.
        $unambiguous = array_filter(
            $bindings,
            static fn(array $receiver, string $variable): bool
                => $receiver !== self::AMBIGUOUS_BINDING && !isset($assigned[$variable]),
            ARRAY_FILTER_USE_BOTH,
        );

        return [$sites, $unambiguous];
    }

    /**
     * A binding is kept only while one loop variable names one receiver. The index has
     * no notion of where a binding stops holding, so a name reused by a second loop
     * over something else is not written down anywhere — and a rule that took the last
     * one would report the first loop against a contract it never called.
     *
     * @param array<string, array{base: string, path: array<int, string>}> $bindings Bindings collected so far
     * @param array<string, array{base: string, path: array<int, string>}> $found Binding of the loop just read
     * @return array<string, array{base: string, path: array<int, string>}> Bindings that still name one receiver
     */
    private function mergeBinding(array $bindings, array $found): array
    {
        foreach ($found as $variable => $receiver) {
            if (array_key_exists($variable, $bindings) && $bindings[$variable] !== $receiver) {
                $bindings[$variable] = self::AMBIGUOUS_BINDING;
                continue;
            }
            $bindings[$variable] = $receiver;
        }

        return $bindings;
    }

    /**
     * @param int $cursor Index of the token before the `new`
     * @param string $class Fully qualified name of the declaring class, for `new self()`
     * @return ?string Fully qualified name of the constructed class, or null when it is not written as a name
     */
    private function raisedException(int $cursor, string $class): ?string
    {
        if (($this->tokens[$cursor + 1][0] ?? null) !== T_NEW) {
            return null;
        }
        $nameToken = $this->tokens[$cursor + 2] ?? null;
        if (!$this->isName($nameToken)) {
            return null;
        }

        return $this->resolveTypeName($nameToken[1]);
    }

    /**
     * Reads one receiver chain — `$this->registry->find()`, `self::$pool->run()`,
     * `Registry::find()` — and stops at the first call, whose result type is unknown
     * and takes the rest of the chain out of scope.
     *
     * @param int $cursor Index the chain starts at
     * @param int $limit Index the chain may not reach past
     * @return array{0: ?CallSite, 1: ?array{base: string, path: array<int, string>}, 2: int}
     */
    private function readChain(int $cursor, int $limit): array
    {
        $token = $this->tokens[$cursor];
        $type = $token[0];
        if ($this->continuesAChain($cursor)) {
            return [null, null, $cursor + 1];
        }
        if ($type === T_VARIABLE) {
            $base = $token[1];
        } elseif ($type === T_STATIC) {
            $base = 'static';
        } elseif ($this->isName($token) && ($this->tokens[$cursor + 1][0] ?? null) === T_DOUBLE_COLON) {
            $written = strtolower($token[1]);
            $base = $written === 'self' || $written === 'parent' ? $written : $this->resolveName($token[1]);
        } else {
            return [null, null, $cursor + 1];
        }

        $cursor++;
        $path = [];
        while ($cursor < $limit) {
            $operator = $this->tokens[$cursor][0];
            $member = $this->tokens[$cursor + 1] ?? null;
            $isArrow = $operator === T_OBJECT_OPERATOR || $operator === T_NULLSAFE_OBJECT_OPERATOR;
            if (!$isArrow && $operator !== T_DOUBLE_COLON) {
                break;
            }
            if ($member === null) {
                return [null, null, $cursor + 1];
            }
            if ($this->isMemberName($member) && ($this->tokens[$cursor + 2][0] ?? null) === '(') {
                if ($this->isFirstClassCallable($cursor + 2)) {
                    return [null, null, $cursor + 4];
                }
                $site = new CallSite(CallSite::KIND_CALL, $member[2], $base, $path, $member[1], []);

                return [$site, null, $cursor + 2];
            }
            if ($isArrow && $this->isName($member)) {
                $path[] = $member[1];
                $cursor += 2;
                continue;
            }
            if (!$isArrow && $member[0] === T_VARIABLE) {
                $path[] = CallSite::STATIC_STEP_PREFIX . ltrim($member[1], '$');
                $cursor += 2;
                continue;
            }

            return [null, null, $cursor + 2];
        }

        return [null, ['base' => $base, 'path' => $path], $cursor];
    }

    /**
     * A chain may only be read from its start. The walk restarts on the token after a
     * call's parenthesis, so without this a *property* would be read as a *class*:
     * `$this->make()->registry->name()` resumes at `registry`, and were a class of
     * that name to exist in the namespace — the lookup is case-insensitive — the rule
     * would demand the `@throws` of a class the code never mentions.
     *
     * @param int $cursor Index the chain would start at
     * @return bool True when the token stands in the member position of a chain already begun
     */
    private function continuesAChain(int $cursor): bool
    {
        $previous = $this->tokens[$cursor - 1][0] ?? null;

        return $previous === T_OBJECT_OPERATOR
            || $previous === T_NULLSAFE_OBJECT_OPERATOR
            || $previous === T_DOUBLE_COLON;
    }

    /**
     * `Foo::bar(...)` looks like a call and is not one: it builds a closure, and
     * whatever the method throws is thrown wherever that closure is later invoked.
     *
     * @param int $cursor Index of the opening parenthesis
     * @return bool True when the parentheses hold the first-class callable ellipsis
     */
    private function isFirstClassCallable(int $cursor): bool
    {
        return ($this->tokens[$cursor + 1][0] ?? null) === T_ELLIPSIS
            && ($this->tokens[$cursor + 2][0] ?? null) === ')';
    }

    /**
     * Binds the loop variable of a `foreach` to the receiver it iterates, so a call on
     * that variable can be resolved through the declared element type of the property.
     *
     * @param int $cursor Index of the `foreach` token
     * @return array<string, array{base: string, path: array<int, string>}> Iterated receiver by loop variable
     */
    private function readForeachBinding(int $cursor): array
    {
        $open = $cursor + 1;
        if (($this->tokens[$open][0] ?? null) !== '(') {
            return [];
        }

        $close = $this->matchingBracket($open, '(', ')');
        $asIndex = null;
        for ($index = $open + 1; $index < $close; $index++) {
            if ($this->tokens[$index][0] === T_AS) {
                $asIndex = $index;
                break;
            }
        }
        if ($asIndex === null) {
            return [];
        }

        $variable = null;
        for ($index = $asIndex + 1; $index < $close; $index++) {
            if ($this->tokens[$index][0] === T_VARIABLE) {
                $variable = $this->tokens[$index][1];
            }
        }
        [, $receiver] = $this->readChain($open + 1, $asIndex);
        if ($variable === null || $receiver === null) {
            return [];
        }

        return [$variable => $receiver];
    }

    /**
     * Reads the try blocks of a body and what each of them swallows. The range is the
     * try block alone: a `catch` body sits outside it, which is what makes a converted
     * exception rethrown there a direct `throw` and not a swallowed one.
     *
     * @param int $start Index of the opening brace of the body
     * @param int $end Index of its closing brace
     * @return array<int, array{start: int, end: int, types: array<int, string>}> Try ranges, innermost last
     */
    private function readCatchRanges(int $start, int $end): array
    {
        $ranges = [];
        for ($cursor = $start; $cursor < $end; $cursor++) {
            if ($this->tokens[$cursor][0] !== T_TRY) {
                continue;
            }
            $open = $cursor + 1;
            if (($this->tokens[$open][0] ?? null) !== '{') {
                continue;
            }

            $close = $this->matchingBrace($open);
            $types = [];
            $after = $close + 1;
            while (($this->tokens[$after][0] ?? null) === T_CATCH) {
                if (($this->tokens[$after + 1][0] ?? null) !== '(') {
                    break;
                }
                $paren = $this->matchingBracket($after + 1, '(', ')');
                for ($index = $after + 2; $index < $paren; $index++) {
                    if ($this->isName($this->tokens[$index])) {
                        $types[] = $this->resolveName($this->tokens[$index][1]);
                    }
                }
                if (($this->tokens[$paren + 1][0] ?? null) !== '{') {
                    break;
                }
                $after = $this->matchingBrace($paren + 1) + 1;
            }
            $ranges[] = ['start' => $open, 'end' => $close, 'types' => $types];
        }

        return $ranges;
    }

    /**
     * @param array<int, array{start: int, end: int, types: array<int, string>}> $ranges Try ranges of the body
     * @param int $cursor Index of the call or throw
     * @return array<int, string> Fully qualified exception types an enclosing catch swallows there
     */
    private function caughtAt(array $ranges, int $cursor): array
    {
        $types = [];
        foreach ($ranges as $range) {
            if ($cursor > $range['start'] && $cursor < $range['end']) {
                $types = [...$types, ...$range['types']];
            }
        }

        return $types;
    }

    /**
     * @param string $class Fully qualified name of the receiver's class
     * @param string $method Lowercased method name
     * @param array<int, string> $seen Classes already visited, guarding a malformed cycle
     * @return ?MethodRecord First declaration found along the resolution order
     */
    private function lookupMethod(string $class, string $method, array $seen): ?MethodRecord
    {
        $key = strtolower(ltrim($class, '\\'));
        if (in_array($key, $seen, true)) {
            return null;
        }

        $record = $this->classes[$key] ?? null;
        if ($record === null) {
            return null;
        }
        if (isset($record->methods[$method])) {
            return $record->methods[$method];
        }

        $seen[] = $key;
        foreach ([...$record->traits, ...$this->contractSources($record)] as $source) {
            $found = $this->lookupMethod($source, $method, $seen);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param ClassRecord $record Class whose inherited contracts are wanted
     * @return array<int, string> Parent first, then interfaces — the order a contract is looked up in
     */
    private function contractSources(ClassRecord $record): array
    {
        return $record->parent === null ? $record->interfaces : [$record->parent, ...$record->interfaces];
    }

    /**
     * @param string $class Fully qualified name of the ancestor to look in
     * @param string $method Lowercased method name
     * @param array<int, string> $seen Classes already visited, guarding a malformed cycle
     * @return ?MethodRecord The inherited declaration, or null when this branch declares none
     */
    private function lookupContract(string $class, string $method, array $seen): ?MethodRecord
    {
        $key = strtolower(ltrim($class, '\\'));
        if (in_array($key, $seen, true)) {
            return null;
        }

        $record = $this->classes[$key] ?? null;
        if ($record === null) {
            return null;
        }
        // A private parent method is not inherited, so nothing below it overrides it.
        if (isset($record->methods[$method]) && !$record->methods[$method]->isPrivateLink()) {
            return $record->methods[$method];
        }

        $seen[] = $key;
        foreach ($this->contractSources($record) as $source) {
            $found = $this->lookupContract($source, $method, $seen);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Every method a class gets from its traits and does not declare itself, which is
     * where an implementation of an interface can sit without the class naming it.
     *
     * @param ClassRecord $record Class to collect for
     * @param array<int, string> $seen Traits already walked, guarding a malformed cycle
     * @return array<int, MethodRecord> Trait-provided methods, in no particular order
     */
    public function traitMethods(ClassRecord $record, array $seen = []): array
    {
        $provided = [];
        foreach ($record->traits as $trait) {
            $used = $this->find($trait);
            if ($used === null || in_array(strtolower($used->name), $seen, true)) {
                continue;
            }
            foreach ([...array_values($used->methods), ...$this->traitMethods($used, [...$seen, strtolower($used->name)])] as $method) {
                if (!isset($record->methods[strtolower($method->name)])) {
                    $provided[strtolower($method->name)] = $method;
                }
            }
        }

        return array_values($provided);
    }

    /**
     * A static property is reached as `Class::$name` and an instance one as `->name`,
     * so the two live in one map under keys that cannot collide.
     *
     * @param string $property Property name without its dollar
     * @param bool $isStatic True when the declaration carries `static`
     * @return string Key the property is stored and looked up under
     */
    private function propertyKey(string $property, bool $isStatic): string
    {
        return $isStatic ? CallSite::STATIC_STEP_PREFIX . $property : $property;
    }

    /**
     * @param array<int, int|string> $modifiers Modifier token types read before the keyword
     * @return string One of the visibility constants of {@see MethodRecord}
     */
    private function visibilityOf(array $modifiers): string
    {
        if (in_array(T_PRIVATE, $modifiers, true)) {
            return MethodRecord::VISIBILITY_PRIVATE;
        }

        return in_array(T_PROTECTED, $modifiers, true)
            ? MethodRecord::VISIBILITY_PROTECTED
            : MethodRecord::VISIBILITY_PUBLIC;
    }

    /**
     * @param ?string $doc Docblock to read
     * @return array<int, string> Fully qualified exception classes named by `@throws`
     */
    private function throwsTags(?string $doc): array
    {
        $classes = [];
        foreach ($this->docTags($doc, 'throws') as $text) {
            [$written] = $this->readTypeExpression($text);
            foreach (explode('|', $written) as $part) {
                $resolved = $this->resolveTypeName(trim($part));
                if ($resolved !== null) {
                    $classes[] = $resolved;
                }
            }
        }

        return $classes;
    }

    /**
     * @param ?string $doc Docblock to read
     * @return array<string, string> Declared type by variable name, an `[]` suffix marking an array of it
     */
    private function paramTags(?string $doc): array
    {
        $types = [];
        foreach ($this->docTags($doc, 'param') as $text) {
            [$written, $length] = $this->readTypeExpression($text);
            $matches = [];
            if (preg_match('/^\s*&?(?:\.\.\.)?(\$\w+)/', substr($text, $length), $matches) !== 1) {
                continue;
            }
            $declared = $this->typeFromDocText($written);
            if ($declared !== null) {
                $types[$matches[1]] = $declared;
            }
        }

        return $types;
    }

    /**
     * Docblock tags are one per line, so the line is the unit — which is what lets the
     * type be read as an expression rather than as "everything up to a space".
     *
     * @param ?string $doc Docblock to read
     * @param string $tag Tag name without its at-sign
     * @return array<int, string> Text following each occurrence of the tag
     */
    private function docTags(?string $doc, string $tag): array
    {
        if ($doc === null) {
            return [];
        }

        $texts = [];
        foreach (explode("\n", $doc) as $line) {
            $matches = [];
            if (preg_match('~^\s*(?:/\*\*|\*)?\s*@(\w[\w-]*)\s+(.+)$~', $line, $matches) !== 1) {
                continue;
            }
            if ($matches[1] === $tag) {
                $texts[] = trim($matches[2]);
            }
        }

        return $texts;
    }

    /**
     * Reads one type off the front of a tag's text, counting brackets rather than
     * stopping at the first space: `array<int, Foo>` is this repository's ordinary
     * spelling, and a whitespace split silently drops every one of them.
     *
     * @param string $text Text following the tag
     * @return array{0: string, 1: int} The type as written, and how many characters it took
     */
    private function readTypeExpression(string $text): array
    {
        $depth = 0;
        $length = strlen($text);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $text[$offset];
            if ($character === '<' || $character === '{' || $character === '(') {
                $depth++;
                continue;
            }
            if ($character === '>' || $character === '}' || $character === ')') {
                $depth--;
                continue;
            }
            if ($depth === 0 && (trim($character) === '' || $character === '*')) {
                break;
            }
        }

        return [substr($text, 0, $offset), $offset];
    }

    /**
     * `self`, `static` and `parent` mean in a tag exactly what they mean in code, and
     * a rule that dropped them would read `@throws self` over a `throw new self()` as
     * a method declaring nothing.
     *
     * @param string $name Class name as written in a docblock
     * @return ?string Fully qualified class name, or null when the name is not a class
     */
    private function resolveTypeName(string $name): ?string
    {
        $written = strtolower(ltrim($name, '?\\'));
        if ($written === 'self' || $written === 'static') {
            return $this->currentClass;
        }
        if ($written === 'parent') {
            return $this->currentParent;
        }

        return $this->isClassLikeName($name) ? $this->resolveName($name) : null;
    }

    /**
     * The signature is read first and the docblock only fills what it cannot carry —
     * the element type of an array, which is where a `foreach` over a property finds
     * what it is iterating.
     *
     * @param array<int, array{0: int|string, 1: string, 2: int}> $typeTokens Tokens standing before the variable
     * @param ?string $doc Docblock directly above the declaration
     * @param string $tag Docblock tag carrying the type, without its at-sign
     * @return ?string Declared type, an `[]` suffix marking an array of it, or null when out of scope
     */
    private function declaredType(array $typeTokens, ?string $doc, string $tag): ?string
    {
        $native = $this->typeFromTokens($typeTokens);
        if ($native !== null) {
            return $native;
        }
        foreach ($this->docTags($doc, $tag) as $text) {
            [$written] = $this->readTypeExpression($text);

            return $this->typeFromDocText($written);
        }

        return null;
    }

    /**
     * @param array<int, array{0: int|string, 1: string, 2: int}> $tokens Tokens standing before the variable
     * @return ?string Fully qualified class name, or null when the type is not a single class
     */
    private function typeFromTokens(array $tokens): ?string
    {
        $names = [];
        foreach ($tokens as $token) {
            if ($token[0] === '?') {
                continue;
            }
            if (!$this->isName($token) && $token[0] !== T_ARRAY && $token[0] !== T_STATIC) {
                return null;
            }
            $names[] = $token[0] === T_STATIC ? 'static' : $token[1];
        }

        return count($names) === 1 ? $this->resolveTypeName($names[0]) : null;
    }

    /**
     * @param string $text Type as written in a docblock
     * @return ?string Fully qualified class name, an `[]` suffix marking an array of it, or null when out of scope
     */
    private function typeFromDocText(string $text): ?string
    {
        $text = trim($text);
        $isArray = false;
        $matches = [];
        if (str_ends_with($text, '[]')) {
            $text = substr($text, 0, -2);
            $isArray = true;
        } elseif (preg_match('/^(?:non-empty-)?(?:list|array|iterable)<(.+)>$/i', $text, $matches) === 1) {
            $inner = $matches[1];
            $comma = strrpos($inner, ',');
            $text = trim($comma === false ? $inner : substr($inner, $comma + 1));
            $isArray = true;
        }

        $parts = array_values(array_filter(
            explode('|', ltrim($text, '?')),
            static fn(string $part): bool => strcasecmp(trim($part), 'null') !== 0,
        ));
        $resolved = count($parts) === 1 ? $this->resolveTypeName(trim($parts[0])) : null;
        if ($resolved === null) {
            return null;
        }

        return $isArray ? $resolved . '[]' : $resolved;
    }

    /**
     * @param string $name Name as written in the source
     * @return bool True when the name can denote a class rather than a built-in type
     */
    private function isClassLikeName(string $name): bool
    {
        if (in_array(strtolower(ltrim($name, '?\\')), self::RESERVED_TYPES, true)) {
            return false;
        }

        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $name) === 1;
    }

    /**
     * @param string $name Name as written in the source
     * @return string Fully qualified name, without a leading backslash
     */
    private function resolveName(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $separator = strpos($name, '\\');
        $head = $separator === false ? $name : substr($name, 0, $separator);
        $imported = $this->aliases[strtolower($head)] ?? null;
        if ($imported !== null) {
            return $separator === false ? $imported : $imported . substr($name, $separator);
        }

        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * @param ?array{0: int|string, 1: string, 2: int} $token Token to judge
     * @return bool True when the token spells a class name
     */
    private function isName(?array $token): bool
    {
        return $token !== null
            && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    /**
     * A member may be named with any reserved word — `match()`, `empty()`, `default()`
     * and `list()` all exist in this tree — and PHP hands each of those back under its
     * own keyword token rather than as `T_STRING`. So a member name is judged by its
     * spelling, unlike a class name, which is judged by its token: a keyword there
     * would name no class.
     *
     * @param ?array{0: int|string, 1: string, 2: int} $token Token to judge
     * @return bool True when the token spells an identifier
     */
    private function isMemberName(?array $token): bool
    {
        return $token !== null && preg_match('/^[A-Za-z_]\w*$/', $token[1]) === 1;
    }

    /**
     * @param int|string $type Token type
     * @return bool True when the token is a member modifier
     */
    private function isModifier(int|string $type): bool
    {
        return $this->isVisibility($type)
            || in_array($type, [T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR], true);
    }

    /**
     * @param int|string $type Token type
     * @return bool True when the token is a visibility keyword
     */
    private function isVisibility(int|string $type): bool
    {
        return in_array($type, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true);
    }

    /**
     * @param int $cursor Index of the `function` keyword
     * @return int Index just past the closure
     */
    private function skipClosure(int $cursor): int
    {
        $cursor = $this->skipSignature($cursor);
        if (($this->tokens[$cursor][0] ?? null) === T_USE) {
            $cursor = $this->matchingBracket($cursor + 1, '(', ')') + 1;
        }
        while (isset($this->tokens[$cursor]) && $this->tokens[$cursor][0] !== '{') {
            if ($this->tokens[$cursor][0] === ';') {
                return $cursor + 1;
            }
            $cursor++;
        }

        return isset($this->tokens[$cursor]) ? $this->matchingBrace($cursor) + 1 : $cursor;
    }

    /**
     * An arrow function has no braces, so its body ends where the expression around it
     * does — at the first separator or closing bracket of the enclosing level.
     *
     * @param int $cursor Index of the `fn` keyword
     * @return int Index just past the arrow function
     */
    private function skipArrowFunction(int $cursor): int
    {
        $cursor = $this->skipSignature($cursor);
        while (isset($this->tokens[$cursor]) && $this->tokens[$cursor][0] !== T_DOUBLE_ARROW) {
            $cursor++;
        }

        $depth = 0;
        for ($cursor++; isset($this->tokens[$cursor]); $cursor++) {
            $type = $this->tokens[$cursor][0];
            if ($type === '(' || $type === '[' || in_array($type, self::BRACE_OPENERS, true)) {
                $depth++;
                continue;
            }
            if ($type === ')' || $type === ']' || $type === '}') {
                if ($depth === 0) {
                    return $cursor;
                }
                $depth--;
                continue;
            }
            if ($depth === 0 && ($type === ',' || $type === ';')) {
                return $cursor;
            }
        }

        return $cursor;
    }

    /**
     * @param int $cursor Index of the `function` or `fn` keyword
     * @return int Index just past the parameter list
     */
    private function skipSignature(int $cursor): int
    {
        while (isset($this->tokens[$cursor]) && $this->tokens[$cursor][0] !== '(') {
            $cursor++;
        }

        return isset($this->tokens[$cursor]) ? $this->matchingBracket($cursor, '(', ')') + 1 : $cursor;
    }

    /**
     * @param int $cursor Index of the `class` keyword of a `new class` expression
     * @return int Index just past the anonymous class
     */
    private function skipAnonymousClass(int $cursor): int
    {
        $cursor++;
        if (($this->tokens[$cursor][0] ?? null) === '(') {
            $cursor = $this->matchingBracket($cursor, '(', ')') + 1;
        }
        while (isset($this->tokens[$cursor]) && $this->tokens[$cursor][0] !== '{') {
            $cursor++;
        }

        return isset($this->tokens[$cursor]) ? $this->matchingBrace($cursor) + 1 : $cursor;
    }

    /**
     * Skips the conflict-resolution block of a trait use, which is a brace pair rather
     * than the usual semicolon.
     */
    private function skipTraitAdaptations(): void
    {
        if (($this->tokens[$this->cursor][0] ?? null) === '{') {
            $this->cursor = $this->matchingBrace($this->cursor) + 1;

            return;
        }

        $this->skipTo(';');
    }

    /**
     * Walks off the end of one class member — a property, a constant, an enum case.
     * Two things end it besides the semicolon: a property hook block, which carries no
     * semicolon at all, and the closing brace of the class body, which is left
     * unconsumed. Without that second stop a member the walk misread would take the
     * rest of the class with it.
     */
    private function skipMember(): void
    {
        while (isset($this->tokens[$this->cursor])) {
            $type = $this->tokens[$this->cursor][0];
            if ($type === '}') {
                return;
            }
            if ($type === '(' || $type === '[') {
                $this->cursor = $this->matchingBracket($this->cursor, $type, $type === '(' ? ')' : ']') + 1;
                continue;
            }
            // A brace at member level is a hook block whether or not a default came
            // first: no constant expression can hold one, so nothing else spells it.
            if (in_array($type, self::BRACE_OPENERS, true)) {
                $this->cursor = $this->matchingBrace($this->cursor) + 1;

                return;
            }
            $this->cursor++;
            if ($type === ';') {
                return;
            }
        }
    }

    /**
     * @param string $stop Single-character token to stop just past
     */
    private function skipTo(string $stop): void
    {
        while (isset($this->tokens[$this->cursor])) {
            $type = $this->tokens[$this->cursor][0];
            if ($type === '(' || $type === '[') {
                $this->cursor = $this->matchingBracket($this->cursor, $type, $type === '(' ? ')' : ']') + 1;
                continue;
            }
            if (in_array($type, self::BRACE_OPENERS, true)) {
                $this->cursor = $this->matchingBrace($this->cursor) + 1;
                continue;
            }
            $this->cursor++;
            if ($type === $stop) {
                return;
            }
        }
    }

    /**
     * @param int $cursor Index of an opening brace
     * @return int Index of the brace that closes it
     */
    private function matchingBrace(int $cursor): int
    {
        $depth = 0;
        for (; isset($this->tokens[$cursor]); $cursor++) {
            $type = $this->tokens[$cursor][0];
            if (in_array($type, self::BRACE_OPENERS, true)) {
                $depth++;
                continue;
            }
            if ($type === '}') {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return $cursor - 1;
    }

    /**
     * `#[` is one token and its `]` is a plain one, so an array literal written inside
     * the attribute closes it early unless both shapes of opener are counted.
     *
     * @param int $cursor Index of the `#[` token
     * @return int Index just past the attribute
     */
    private function skipAttribute(int $cursor): int
    {
        $depth = 0;
        for (; isset($this->tokens[$cursor]); $cursor++) {
            $type = $this->tokens[$cursor][0];
            if ($type === T_ATTRIBUTE || $type === '[') {
                $depth++;
                continue;
            }
            if ($type === ']') {
                $depth--;
                if ($depth === 0) {
                    return $cursor + 1;
                }
            }
        }

        return $cursor;
    }

    /**
     * @param int $cursor Index of an opening bracket
     * @param int|string $open Opening bracket character, or the token type that stands for one
     * @param string $close Closing bracket character
     * @return int Index of the bracket that closes it
     */
    private function matchingBracket(int $cursor, int|string $open, string $close): int
    {
        $depth = 0;
        for (; isset($this->tokens[$cursor]); $cursor++) {
            $type = $this->tokens[$cursor][0];
            if ($type === $open) {
                $depth++;
                continue;
            }
            if ($type === $close) {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return $cursor - 1;
    }
}

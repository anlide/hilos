<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\NeighbourDeclarations;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces qualified-names.md: executable code names a class by its imported short
 * name, and a short name written there resolves to a class.
 *
 * The two halves are one rule because they are the two sides of one edit. Rewriting
 * `catch (\Throwable $e)` as `catch (Throwable $e)` without adding the import turns
 * a working catch into a catch of `Current\Namespace\Throwable`, which matches
 * nothing and says nothing. Half one asks for the short name; half two makes sure
 * the short name still points at a class.
 *
 * A backslash inside a string literal or inside a comment is out of scope by
 * construction: neither tokenizes as a name, and this rule reads tokens.
 *
 * A name partially qualified against the current namespace is judged by half one
 * too: it carries no backslash to remove but breaks the same rule, and it fails the
 * same silent way once the namespace it is read against changes.
 *
 * Half two is deliberately narrow. It judges the positions where a name pointing
 * nowhere fails in silence or fails far from the line that broke it — `catch`,
 * `new`, `instanceof`, `extends`, `implements`, a static access, and a parameter,
 * property or return type — and leaves the rest to the fatal error that arrives at
 * the first call.
 */
final class CodeFqnRule implements CodeStyleRule
{
    public const string ID = 'CODE-FQN';

    private const string DOC = 'docs/agents/code-style/qualified-names.md';

    /** Names that answer to the enclosing class rather than to an import. */
    private const array SELF_REFERENCES = ['self', 'static', 'parent'];

    /**
     * Built-in types a type position may carry. They reach the rule as `T_STRING`
     * the way a class name does, and none of them is a class to import.
     *
     * @var array<int, string>
     */
    private const array BUILT_IN_TYPES = [
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'string', 'true', 'void',
    ];

    /**
     * Keywords a class member may open with, before the type of a property.
     *
     * @var array<int, int>
     */
    private const array MEMBER_MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_STATIC, T_VAR];

    /**
     * Keywords that declare a type this file owns, whatever the namespace resolves to.
     *
     * @var array<int, int>
     */
    private const array TYPE_DECLARATIONS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * Token types a name reaches the rule as, fully qualified spellings included.
     *
     * @var array<int, int>
     */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * Operators that make whatever follows them a member of something, never a name
     * of its own.
     *
     * @var array<int, int>
     */
    private const array MEMBER_ACCESS_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    private readonly NeighbourDeclarations $neighbours;

    /**
     * @param string $root Absolute path of the scanned root, needed to read the neighbours of a file
     */
    public function __construct(string $root)
    {
        $this->neighbours = new NeighbourDeclarations($root);
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
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return iterable<Violation> Hits of both halves, in file order
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $significant = $this->significantTokens($tokens);
        $namespace = $this->namespaceOf($significant);
        $known = $this->knownNames($significant);
        $depth = 0;
        $declaring = false;

        foreach ($significant as $index => $token) {
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if ($token === ';') {
                $declaring = false;
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($depth === 0 && $this->opensImportOrNamespace($significant, $index)) {
                $declaring = true;
                continue;
            }
            if ($declaring) {
                continue;
            }

            if (in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                yield new Violation(self::ID, $relativePath, $token[2], $this->writtenOutMessage($token[1]));
                continue;
            }

            foreach ($this->judgedNames($significant, $index) as $name) {
                if ($this->resolves($name[1], $relativePath, $namespace, $known)) {
                    continue;
                }

                yield new Violation(
                    self::ID,
                    $relativePath,
                    $name[2],
                    sprintf('%s resolves to no class; the import is missing', $name[1]),
                );
            }
        }
    }

    /**
     * The advice depends on what the name turns out to be. A partially qualified name
     * carries no backslash to remove but breaks the same rule, and reads against
     * whatever namespace the file happens to declare. A global function or constant
     * needs no import at all: for those two, and only those two, PHP falls back to the
     * global namespace on its own, so telling the author to import them would send
     * them after a class symbol that does not exist.
     *
     * @param string $written The name exactly as code spells it
     * @return string Message of the hit
     */
    private function writtenOutMessage(string $written): string
    {
        $name = ltrim($written, '\\');

        if (!str_starts_with($written, '\\')) {
            return sprintf('%s is written against the current namespace; import it and use the short name', $name);
        }
        if (!$this->isType($name) && (function_exists($name) || defined($name))) {
            return sprintf(
                '\%s is written out in code; a global function or constant takes the short name and no import',
                $name,
            );
        }

        return sprintf('\%s is written out in code; import it and use the short name', $name);
    }

    /**
     * @param string $name Fully qualified name, without its leading backslash
     * @return bool True when the name is a class, an interface, an enum or a trait
     */
    private function isType(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
    }

    /**
     * A name inside an import or a namespace declaration is not a reference in code:
     * that statement is where the short name comes FROM. A `use` at brace depth zero
     * whose next token is a parenthesis captures variables for a top-level closure and
     * brings in no name at all.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the token under test
     * @return bool True when the token opens an import or a namespace declaration
     */
    private function opensImportOrNamespace(array $significant, int $index): bool
    {
        $token = $significant[$index];
        if (!is_array($token) || ($token[0] !== T_USE && $token[0] !== T_NAMESPACE)) {
            return false;
        }

        return ($significant[$index + 1] ?? null) !== '(';
    }

    /**
     * The judged positions of half two, read from the token that opens each of them.
     * A name reached through several of them at once is impossible by construction:
     * every branch below starts at a keyword, and a static access starts at the name
     * itself, which no keyword branch hands back.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the token that may open a judged position
     * @return array<int, array{0: int, 1: string, 2: int}> Short names judged because of this token
     */
    private function judgedNames(array $significant, int $index): array
    {
        $token = $significant[$index];

        return match (true) {
            !is_array($token) => [],
            $token[0] === T_CATCH => $this->namesUntil($significant, $index + 1, [')'], []),
            $token[0] === T_NEW, $token[0] === T_INSTANCEOF => $this->nameAt($significant, $index + 1),
            $token[0] === T_EXTENDS => $this->namesUntil($significant, $index + 1, ['{'], [T_IMPLEMENTS]),
            $token[0] === T_IMPLEMENTS => $this->namesUntil($significant, $index + 1, ['{'], []),
            $token[0] === T_FUNCTION, $token[0] === T_FN => $this->signatureNames($significant, $index),
            $this->opensPropertyDeclaration($significant, $index) => $this->propertyTypeNames($significant, $index),
            $this->opensStaticAccess($significant, $index) => [$token],
            default => [],
        };
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $from Position the collection starts at
     * @param array<int, string> $stopCharacters Single-character tokens that end the collection
     * @param array<int, int> $stopTypes Token types that end the collection
     * @return array<int, array{0: int, 1: string, 2: int}> Short names standing before the first stop
     */
    private function namesUntil(array $significant, int $from, array $stopCharacters, array $stopTypes): array
    {
        $names = [];

        for ($index = $from; isset($significant[$index]); $index++) {
            $token = $significant[$index];

            if (!is_array($token)) {
                if (in_array($token, $stopCharacters, true)) {
                    return $names;
                }
                continue;
            }
            if (in_array($token[0], $stopTypes, true)) {
                return $names;
            }
            if ($token[0] === T_STRING) {
                $names[] = $token;
            }
        }

        return $names;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position the name is expected at
     * @return array<int, array{0: int, 1: string, 2: int}> The name, or nothing when something else stands there
     */
    private function nameAt(array $significant, int $index): array
    {
        $token = $significant[$index] ?? null;

        return is_array($token) && $token[0] === T_STRING ? [$token] : [];
    }

    /**
     * Reads the parameter types and the return type of one signature. A name is a
     * parameter type when a variable follows it: everything a default value carries
     * is dropped at the comma that closes the parameter, and an attribute is stepped
     * over whole, so its arguments never pass for a type.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $functionIndex Position of the `function` or `fn` keyword
     * @return array<int, array{0: int, 1: string, 2: int}> Short names standing in a type position of this signature
     */
    private function signatureNames(array $significant, int $functionIndex): array
    {
        $names = [];
        $pending = [];
        $depth = 0;

        for ($index = $functionIndex + 1; isset($significant[$index]); $index++) {
            $token = $significant[$index];

            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return [...$names, ...$this->returnTypeNames($significant, $index + 1)];
                }
                continue;
            }
            if ($depth === 0) {
                continue;
            }
            if ($token === ',' || $token === '=') {
                $pending = [];
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_ATTRIBUTE) {
                $index = $this->endOfAttribute($significant, $index);
                $pending = [];
                continue;
            }
            if ($token[0] === T_VARIABLE) {
                $names = [...$names, ...$pending];
                $pending = [];
                continue;
            }
            if ($token[0] === T_STRING) {
                $pending[] = $token;
            }
        }

        return $names;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position directly behind the closing parenthesis of the parameter list
     * @return array<int, array{0: int, 1: string, 2: int}> Short names standing in the return type
     */
    private function returnTypeNames(array $significant, int $index): array
    {
        $token = $significant[$index] ?? null;

        // A closure carries its captured variables between the parameter list and the return type.
        if (is_array($token) && $token[0] === T_USE) {
            return $this->returnTypeNames($significant, $this->endOfParentheses($significant, $index + 1) + 1);
        }

        return $token === ':' ? $this->namesUntil($significant, $index + 1, ['{', ';'], [T_DOUBLE_ARROW]) : [];
    }

    /**
     * A property declaration is read from the first of its modifiers, so a type
     * spelled behind several of them is collected once. Only what a type position
     * may hold is stepped over: anything else means the member is not a property,
     * which is how `static::` and `private const` leave the walk.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the first modifier of the member
     * @return array<int, array{0: int, 1: string, 2: int}> Short names standing in the property type
     */
    private function propertyTypeNames(array $significant, int $index): array
    {
        $names = [];

        for ($offset = $index + 1; isset($significant[$offset]); $offset++) {
            $token = $significant[$offset];

            if ($token === '?' || $token === '|' || $token === '&') {
                continue;
            }
            if (!is_array($token)) {
                return [];
            }
            if ($token[0] === T_VARIABLE) {
                return $names;
            }
            if ($token[0] === T_STRING) {
                $names[] = $token;
                continue;
            }
            if (!in_array($token[0], [...self::MEMBER_MODIFIERS, T_ARRAY, T_CALLABLE], true)) {
                return [];
            }
        }

        return $names;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the token under test
     * @return bool True when the token is the first modifier of a class member
     */
    private function opensPropertyDeclaration(array $significant, int $index): bool
    {
        $token = $significant[$index];
        $previous = $significant[$index - 1] ?? null;

        return is_array($token)
            && in_array($token[0], self::MEMBER_MODIFIERS, true)
            && (!is_array($previous) || !in_array($previous[0], self::MEMBER_MODIFIERS, true));
    }

    /**
     * A property holding a class name is accessed the same way a class is —
     * `$this->hilosClass::BROWSER_TABLES` — and the receiver is not a name to import.
     * What stands in front tells the two apart: an object or a class operator can
     * only be followed by a member.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the token under test
     * @return bool True when the token is a short name a static access is written through
     */
    private function opensStaticAccess(array $significant, int $index): bool
    {
        $token = $significant[$index];
        $previous = $significant[$index - 1] ?? null;
        $next = $significant[$index + 1] ?? null;

        return is_array($token) && $token[0] === T_STRING
            && is_array($next) && $next[0] === T_DOUBLE_COLON
            && !(is_array($previous) && in_array($previous[0], self::MEMBER_ACCESS_OPERATORS, true));
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the `#[` token
     * @return int Position of the bracket that closes the attribute, or the last position of the stream
     */
    private function endOfAttribute(array $significant, int $index): int
    {
        $depth = 0;

        for ($offset = $index; isset($significant[$offset]); $offset++) {
            $token = $significant[$offset];

            if ($token === ']') {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
                continue;
            }
            if ($token === '[' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
                $depth++;
            }
        }

        return count($significant) - 1;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the opening parenthesis
     * @return int Position of the parenthesis that closes it, or the last position of the stream
     */
    private function endOfParentheses(array $significant, int $index): int
    {
        $depth = 0;

        for ($offset = $index; isset($significant[$offset]); $offset++) {
            if ($significant[$offset] === '(') {
                $depth++;
                continue;
            }
            if ($significant[$offset] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
            }
        }

        return count($significant) - 1;
    }

    /**
     * Reads the import table and the types this file declares. Imports are taken at
     * brace depth zero only: the same keyword inside a class body brings in a trait,
     * and inside a closure signature it captures variables.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @return array<string, true> Lowercased short names this file resolves on its own
     */
    private function knownNames(array $significant): array
    {
        $known = [];
        $depth = 0;

        foreach ($significant as $index => $token) {
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth--;
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], self::TYPE_DECLARATIONS, true)) {
                $declared = $significant[$index + 1] ?? null;
                if (is_array($declared) && $declared[0] === T_STRING) {
                    $known[strtolower($declared[1])] = true;
                }
                continue;
            }
            if ($token[0] === T_USE && $depth === 0) {
                foreach ($this->importedNames($significant, $index) as $alias) {
                    $known[strtolower($alias)] = true;
                }
            }
        }

        return $known;
    }

    /**
     * Reads one `use` statement into the names it makes available. A grouped import
     * is read by the same walk: its prefix is overwritten by every name inside the
     * braces, and an alias overwrites the name it renames.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $useIndex Position of the `use` keyword
     * @return array<int, string> Short names this statement brings into the file
     */
    private function importedNames(array $significant, int $useIndex): array
    {
        $aliases = [];
        $current = null;
        $renaming = false;

        for ($index = $useIndex + 1; isset($significant[$index]); $index++) {
            $token = $significant[$index];

            if ($token === ';') {
                break;
            }
            if ($token === '(') {
                return [];
            }
            if ($token === ',') {
                $aliases = $current === null ? $aliases : [...$aliases, $current];
                $current = null;
                $renaming = false;
                continue;
            }
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_FUNCTION || $token[0] === T_CONST) {
                return [];
            }
            if ($token[0] === T_AS) {
                $renaming = true;
                continue;
            }
            if (in_array($token[0], self::NAME_TOKENS, true)) {
                $segments = explode('\\', trim($token[1], '\\'));
                $current = $renaming ? $token[1] : (string)end($segments);
                $renaming = false;
            }
        }

        return $current === null ? $aliases : [...$aliases, $current];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @return string Namespace this file declares, empty when it declares none
     */
    private function namespaceOf(array $significant): string
    {
        foreach ($significant as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $name = $significant[$index + 1] ?? null;
            if (is_array($name) && in_array($name[0], self::NAME_TOKENS, true)) {
                return trim($name[1], '\\');
            }
        }

        return '';
    }

    /**
     * A short name resolves against the namespace it is written in, never against the
     * global one, so a global class needs the same import a namespaced one does. The
     * neighbours come before the autoloader because a fixture, a test double and any
     * other class the autoloader does not own still resolve for PHP itself: PSR-4
     * makes the same namespace the same directory.
     *
     * @param string $name Short name written in code
     * @param string $relativePath File path relative to the scanned root
     * @param string $namespace Namespace the file declares
     * @param array<string, true> $known Lowercased names the file imports or declares
     * @return bool True when the name points at a class
     */
    private function resolves(string $name, string $relativePath, string $namespace, array $known): bool
    {
        $lowered = strtolower($name);
        if (in_array($lowered, self::SELF_REFERENCES, true) || in_array($lowered, self::BUILT_IN_TYPES, true)) {
            return true;
        }
        if (isset($known[$lowered])) {
            return true;
        }
        if ($this->neighbours->declares($relativePath, $name)) {
            return true;
        }

        $qualified = $namespace === '' ? $name : $namespace . '\\' . $name;

        return class_exists($qualified) || interface_exists($qualified)
            || enum_exists($qualified) || trait_exists($qualified);
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<int, string|array{0: int, 1: string, 2: int}> The same stream without the ignorable tokens
     */
    private function significantTokens(array $tokens): array
    {
        $significant = [];

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        return $significant;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\ClassDocReader;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Reads collection method contracts without loading classes or inferring body types.
 * Token streams and PHPDoc fragments stay arrays at this lexical tooling boundary.
 */
final class CollectionObjectListRule implements CodeStyleRule
{
    public const string ID = 'COLLECTION-OBJECT-LIST';

    private const string DOC = 'docs/agents/code-style/internal-backend-api.md';

    /** @var list<string> Builtins and PHPDoc constructors that do not denote an object element */
    private const array NON_OBJECT_TYPES = [
        'array', 'non-empty-array', 'list', 'non-empty-list', 'iterable', 'callable',
        'pure-callable', 'resource', 'closed-resource', 'open-resource',
        'string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'true', 'false',
        'null', 'mixed', 'scalar', 'numeric', 'number', 'void', 'never', 'never-return',
        'never-returns', 'no-return', 'noreturn', 'array-key', 'class-string',
        'interface-string', 'trait-string', 'enum-string', 'callable-string',
        'numeric-string', 'literal-string', 'non-empty-string', 'non-falsy-string',
        'lowercase-string', 'uppercase-string', 'non-empty-lowercase-string',
        'non-empty-uppercase-string', 'positive-int', 'negative-int', 'non-negative-int',
        'non-positive-int', 'literal-int', 'key-of', 'value-of', 'int-mask', 'int-mask-of',
    ];

    /**
     * @param string $root Scanned root relative to the repository root
     */
    public function __construct(private readonly string $root)
    {
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
     * @return iterable<Violation> One entry per violating method annotation
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        if (preg_match('~^(?:framework/backend|demo/[^/]+/backend)$~D', $this->root) !== 1
            || preg_match('~(?:^|/)Database/(?:Object|View)/Collection/~', $relativePath) !== 1) {
            return;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn(string|array $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT], true),
        ));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }

            $opening = ClassDocReader::classOpening($tokens, $index);
            if ($opening !== null) {
                yield from $this->checkClass($relativePath, $tokens, $opening, $this->templates(ClassDocReader::precedingDoc($tokens, $index)));
            }
        }
    }

    /**
     * @param string $path File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens, including docblocks
     * @param int $opening Opening brace of the class body
     * @param array<string, string|null> $templates Class-local template bounds; null means unconstrained
     * @return iterable<Violation> Hits on direct members only, never nested functions or closures
     */
    private function checkClass(string $path, array $tokens, int $opening, array $templates): iterable
    {
        for ($index = $opening + 1; isset($tokens[$index]) && $tokens[$index] !== '}'; $index++) {
            $token = $tokens[$index];
            if ($token === '{' || $token === '(' || $token === '[' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
                $index = ClassDocReader::bracketEnd($tokens, $index);
                continue;
            }
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $index + 1;
            $name = $tokens[$nameIndex] ?? null;
            if (is_array($name) && $name[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                $name = $tokens[++$nameIndex] ?? null;
            }
            if (!is_array($name) || $name[0] !== T_STRING || ($tokens[$nameIndex + 1] ?? null) !== '(') {
                continue;
            }
            $doc = ClassDocReader::precedingDoc($tokens, $index);
            if ($doc !== null) {
                yield from $this->checkMethod(
                    $path,
                    $name[1],
                    $doc,
                    $this->parameters($tokens, $nameIndex + 1),
                    array_replace($templates, $this->templates($doc)),
                );
            }
        }
    }

    /**
     * @param string $path File path relative to the scanned root
     * @param string $method Declared method name
     * @param array{0: int, 1: string, 2: int} $doc Attached T_DOC_COMMENT
     * @param list<string> $parameters Real parameter names, including the dollar sign
     * @param array<string, string|null> $templates Local template bounds
     * @return iterable<Violation> Violating tags, in source order
     */
    private function checkMethod(string $path, string $method, array $doc, array $parameters, array $templates): iterable
    {
        foreach (ClassDocReader::tags($doc) as $tag) {
            if (!in_array($tag['name'], ['param', 'return'], true)
                || ($tag['name'] === 'param' && strcasecmp($method, 'fromArray') === 0)
                || ($tag['name'] === 'return' && strcasecmp($method, 'toArray') === 0)) {
                continue;
            }
            $type = ClassDocReader::typeBeforeDescription($tag['text']);
            $label = $method . ' @' . $tag['name'];
            if ($tag['name'] === 'param') {
                $rest = substr($tag['text'], strlen($type));
                if (preg_match('/^\s*(?:&\s*)?(?:\.\.\.)?(\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\b/', $rest, $match) !== 1
                    || !in_array($match[1], $parameters, true)) {
                    continue;
                }
                $label .= ' ' . $match[1];
            }
            if ($this->hasObjectList($type, $templates)) {
                yield new Violation(self::ID, $path, $tag['line'], $label . ' ' . $type . ': use a typed collection');
            }
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $opening Parameter list opening
     * @return list<string> Actual parameter names, excluding attribute and default-expression variables
     */
    private function parameters(array $tokens, int $opening): array
    {
        $names = [];
        for ($cursor = $opening + 1; isset($tokens[$cursor]) && $tokens[$cursor] !== ')'; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '(' || $token === '[' || $token === '{' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
                $cursor = ClassDocReader::bracketEnd($tokens, $cursor);
            } elseif (is_array($token) && $token[0] === T_VARIABLE) {
                $names[] = $token[1];
            }
        }

        return $names;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|null $doc Local class or method doc comment
     * @return array<string, string|null> Declared template names with optional bounds
     */
    private function templates(?array $doc): array
    {
        $templates = [];
        foreach ($doc === null ? [] : ClassDocReader::tags($doc) as $tag) {
            if (preg_match('/^(?:(?:phpstan|psalm)-)?template(?:-covariant|-contravariant)?$/', $tag['name']) === 1
                && preg_match('/^(\w+)(?:\s+(?:of|as)\s+(.+))?/', $tag['text'], $match) === 1) {
                $templates[$match[1]] = isset($match[2]) ? ClassDocReader::typeBeforeDescription($match[2]) : null;
            }
        }

        return $templates;
    }

    /**
     * @param string $type Type expression in a method signature
     * @param array<string, string|null> $templates Local template bounds
     * @return bool Whether any top-level branch is a raw container of direct object elements
     */
    private function hasObjectList(string $type, array $templates): bool
    {
        $type = $this->unwrapped($type);
        $branches = ClassDocReader::splitType($type, '|&');
        if (count($branches) > 1) {
            foreach ($branches as $branch) {
                if ($this->hasObjectList($branch, $templates)) {
                    return true;
                }
            }

            return false;
        }
        if (str_ends_with($type, '[]')) {
            return $this->isObject(substr($type, 0, -2), $templates);
        }
        if (preg_match('/^(list|non-empty-list|array|non-empty-array)\s*<(.*)>$/is', $type, $match) === 1) {
            $arguments = ClassDocReader::splitType($match[2], ',');
            if (count($arguments) === 1 || (count($arguments) === 2 && str_ends_with(strtolower($match[1]), 'array'))) {
                return $this->isObject($arguments[array_key_last($arguments)], $templates);
            }
        }

        return false;
    }

    /**
     * @param string $type Immediate element type, not a key or an arbitrary nested generic argument
     * @param array<string, string|null> $templates Local template bounds; a visited bound becomes unconstrained
     * @return bool Whether at least one branch denotes an object
     */
    private function isObject(string $type, array $templates): bool
    {
        $type = $this->unwrapped($type);
        $branches = ClassDocReader::splitType($type, '|&');
        if (count($branches) > 1) {
            foreach ($branches as $branch) {
                if ($this->isObject($branch, $templates)) {
                    return true;
                }
            }

            return false;
        }
        if (array_key_exists($type, $templates)) {
            $bound = $templates[$type];
            $templates[$type] = null;

            return $bound !== null && $this->isObject($bound, $templates);
        }
        if (str_ends_with($type, '[]') || str_contains($type, '{')) {
            return false;
        }
        if ($type === '$this') {
            return true;
        }
        if (preg_match('/^([\\\\a-zA-Z_\x80-\xff][\\\\a-zA-Z0-9_\x80-\xff-]*)(?:\s*<.*>)?$/s', $type, $match) !== 1) {
            return false;
        }

        return !in_array(strtolower($match[1]), self::NON_OBJECT_TYPES, true);
    }

    /**
     * @param string $type Type fragment
     * @return string Fragment without nullable and enclosing parenthesis wrappers
     */
    private function unwrapped(string $type): string
    {
        $type = ltrim(trim($type), '?');
        while (str_starts_with($type, '(') && str_ends_with($type, ')')) {
            $inside = substr($type, 1, -1);
            // A pair encloses the whole type only when its inside has balanced brackets.
            if (count(ClassDocReader::splitType($inside, ')')) !== 1) {
                break;
            }
            $type = ltrim(trim($inside), '?');
        }

        return $type;
    }
}

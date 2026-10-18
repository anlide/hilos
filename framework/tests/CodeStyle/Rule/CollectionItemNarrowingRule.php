<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\ClassDocReader;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Reads whether a concrete collection narrows its item readers to the class its element
 * constant names: a View collection its `first()`, `last()`, `offsetGet()` to `DB_ITEM_CLASS`
 * and `getObjectCollection()` to `OBJECT_COLLECTION_CLASS`, an Object collection its
 * `first()`, `last()`, `get()`, `offsetGet()` to `OBJECT_CLASS`, a state collection its
 * `get()` and `offsetGet()` to `STATE_CLASS`. A View or Object collection narrows with a
 * class-level `@method` tag or a real method; a state collection only with a real method,
 * because a tag cannot make `offsetGet()` throw on a missing row.
 *
 * Lexical only: the constant is read as written in this file, a subclass that does not
 * redeclare it is not judged, and no generic bound stands in for the name.
 */
final class CollectionItemNarrowingRule implements CodeStyleRule
{
    public const string ID = 'COLLECTION-ITEM-NARROWING';

    private const string DOC = 'docs/agents/orm/db-collection.md';

    private const string LAYER_VIEW = 'View';

    private const string LAYER_OBJECT = 'Object';

    private const string LAYER_RT = 'RT';

    /** @var array<string, string> Path segment that puts a file into each layer */
    private const array LAYER_PATHS = [
        self::LAYER_VIEW => '~(?:^|/)Database/View/Collection/~',
        self::LAYER_OBJECT => '~(?:^|/)Database/Object/Collection/~',
        self::LAYER_RT => '~(?:^|/)Runtime/State/Collection/~',
    ];

    /** @var array<string, array<string, list<string>>> Readers that must name the class of each element constant, by layer, in report order */
    private const array READERS = [
        self::LAYER_VIEW => [
            'OBJECT_COLLECTION_CLASS' => ['getObjectCollection'],
            'DB_ITEM_CLASS' => ['first', 'last', 'offsetGet'],
        ],
        self::LAYER_OBJECT => ['OBJECT_CLASS' => ['first', 'last', 'get', 'offsetGet']],
        self::LAYER_RT => ['STATE_CLASS' => ['get', 'offsetGet']],
    ];

    /** @var array<string, string> Signature the suggested `@method` tag carries, by reader */
    private const array METHOD_SIGNATURES = [
        'getObjectCollection' => 'getObjectCollection()',
        'first' => 'first()',
        'last' => 'last()',
        'get' => 'get(int|string $key)',
        'offsetGet' => 'offsetGet(mixed $offset)',
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
     * @return iterable<Violation> One entry per reader that does not name the element, on the line of the class keyword
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $layer = $this->layer($relativePath);
        if (preg_match('~^(?:framework/backend|demo/[^/]+/backend)$~D', $this->root) !== 1 || $layer === null) {
            return;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn(string|array $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT], true),
        ));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            $name = $tokens[$index + 1] ?? null;
            // `Foo::class` is a constant and `new class` an anonymous declaration: neither is a judged class.
            if ((is_array($previous) && $previous[0] === T_DOUBLE_COLON) || !is_array($name) || $name[0] !== T_STRING) {
                continue;
            }

            $opening = ClassDocReader::classOpening($tokens, $index);
            if ($opening !== null) {
                yield from $this->checkClass($relativePath, $tokens, $index, $opening, $layer);
            }
        }
    }

    /**
     * @param string $relativePath File path relative to the scanned root
     * @return string|null Layer the path puts the file into, or null outside the three collection layers
     */
    private function layer(string $relativePath): ?string
    {
        foreach (self::LAYER_PATHS as $layer => $pattern) {
            if (preg_match($pattern, $relativePath) === 1) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * @param string $path File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens, including docblocks
     * @param int $index Class keyword index
     * @param int $opening Opening brace of the class body
     * @param string $layer Layer the file sits in
     * @return iterable<Violation> Readers that name no element, per declared constant, in report order
     */
    private function checkClass(string $path, array $tokens, int $index, int $opening, string $layer): iterable
    {
        $elements = $this->elementConstants($tokens, $opening);
        $declared = $this->returnTypes($tokens, $opening);
        if ($layer !== self::LAYER_RT) {
            foreach ($this->documentedTypes(ClassDocReader::precedingDoc($tokens, $index)) as $method => $types) {
                $declared[$method] = array_merge($declared[$method] ?? [], $types);
            }
        }
        foreach (self::READERS[$layer] as $constant => $readers) {
            if (!isset($elements[$constant])) {
                continue;
            }
            foreach ($readers as $reader) {
                $types = $declared[strtolower($reader)] ?? [];
                $naming = array_filter(
                    $types,
                    fn(string $type): bool => $this->branches($type) === [$elements[$constant]],
                );
                if ($naming === []) {
                    $found = $types === [] ? null : implode('|', $this->branches($types[0]));
                    yield new Violation(self::ID, $path, $tokens[$index][2], $this->message($layer, $reader, $elements[$constant], $found));
                }
            }
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $opening Opening brace of the class body
     * @return iterable<int> Indexes of the direct members' `const` and `function` keywords, nested bodies skipped
     */
    private function directMembers(array $tokens, int $opening): iterable
    {
        for ($index = $opening + 1; isset($tokens[$index]) && $tokens[$index] !== '}'; $index++) {
            $token = $tokens[$index];
            if ($token === '{' || $token === '(' || $token === '[' || (is_array($token) && $token[0] === T_ATTRIBUTE)) {
                $index = ClassDocReader::bracketEnd($tokens, $index);
            } elseif (is_array($token) && in_array($token[0], [T_CONST, T_FUNCTION], true)) {
                yield $index;
            }
        }
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $opening Opening brace of the class body
     * @return array<string, string> Class name each `const NAME = Name::class;` member writes, by constant; any other value is absent
     */
    private function elementConstants(array $tokens, int $opening): array
    {
        $elements = [];
        foreach ($this->directMembers($tokens, $opening) as $index) {
            if ($tokens[$index][0] !== T_CONST) {
                continue;
            }
            $cursor = $index + 1;
            while (isset($tokens[$cursor]) && $tokens[$cursor] !== '=' && $tokens[$cursor] !== ';') {
                $cursor++;
            }
            $name = $tokens[$cursor - 1];
            $value = $tokens[$cursor + 1] ?? null;
            $operator = $tokens[$cursor + 2] ?? null;
            $keyword = $tokens[$cursor + 3] ?? null;
            if (($tokens[$cursor] ?? null) === '=' && is_array($name) && $name[0] === T_STRING
                && is_array($value) && in_array($value[0], [T_STRING, T_NAME_QUALIFIED], true)
                && is_array($operator) && $operator[0] === T_DOUBLE_COLON
                && is_array($keyword) && $keyword[0] === T_CLASS
                && ($tokens[$cursor + 4] ?? null) === ';') {
                $elements[$name[1]] = $value[1];
            }
        }

        return $elements;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Significant tokens
     * @param int $opening Opening brace of the class body
     * @return array<string, list<string>> Native return types of the direct methods, by lowercased name; a method without one is absent
     */
    private function returnTypes(array $tokens, int $opening): array
    {
        $types = [];
        foreach ($this->directMembers($tokens, $opening) as $index) {
            if ($tokens[$index][0] !== T_FUNCTION) {
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
            $cursor = ClassDocReader::bracketEnd($tokens, $nameIndex + 1) + 1;
            if (($tokens[$cursor] ?? null) !== ':') {
                continue;
            }
            $type = '';
            for ($cursor++; isset($tokens[$cursor]) && $tokens[$cursor] !== '{' && $tokens[$cursor] !== ';'; $cursor++) {
                $type .= is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
            }
            $types[strtolower($name[1])][] = $type;
        }

        return $types;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|null $doc Class doc comment
     * @return array<string, list<string>> Types the `@method` tags declare, by lowercased method name; a tag without a type is absent
     */
    private function documentedTypes(?array $doc): array
    {
        $types = [];
        foreach ($doc === null ? [] : ClassDocReader::tags($doc) as $tag) {
            if ($tag['name'] !== 'method') {
                continue;
            }
            $type = ClassDocReader::typeBeforeDescription($tag['text']);
            $rest = substr($tag['text'], strlen($type));
            if (preg_match('/^\s*([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/', $rest, $match) === 1) {
                $types[strtolower($match[1])][] = $type;
            }
        }

        return $types;
    }

    /**
     * @param string $type Declared type expression
     * @return list<string> Its top-level branches, the leading `?` and the null branch dropped
     */
    private function branches(string $type): array
    {
        return array_values(array_filter(
            ClassDocReader::splitType(ltrim(trim($type), '?'), '|'),
            static fn(string $branch): bool => strcasecmp($branch, 'null') !== 0,
        ));
    }

    /**
     * @param string $layer Layer the class sits in
     * @param string $reader Reader that names no element
     * @param string $element Class name the constant writes
     * @param string|null $found What the reader names instead, or null when nothing counted is declared
     * @return string Reportable essence of the hit
     */
    private function message(string $layer, string $reader, string $element, ?string $found): string
    {
        $noun = $layer === self::LAYER_RT ? 'state' : 'item';
        $verdict = $found === null
            ? sprintf('%s() does not name the %s %s', $reader, $noun, $element)
            : sprintf('%s() names %s instead of the %s %s', $reader, $found, $noun, $element);
        if ($layer === self::LAYER_RT) {
            return $verdict . sprintf(
                '; declare it as a method: get(?string $id): ?%1$s, offsetGet(mixed $offset): %1$s that throws when the row is missing'
                . ' (docs/agents/runtime/rt-state.md)',
                $element,
            );
        }

        return $verdict . sprintf('; declare `@method %s|null %s`', $element, self::METHOD_SIGNATURES[$reader]);
    }
}

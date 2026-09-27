<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Database\Actions\Collection\DbActions as CollectionDbActions;
use Hilos\Database\Actions\Item\DbActions as ItemDbActions;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Collection\ObjectCollection;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces inheritance.md: the framework's ORM chain is open, and a link of it builds
 * the rows of its own chain through the link constants, never by naming a framework class.
 *
 * The two halves are one rule because they are the two ways the chain closes back.
 * Half one is a class of the chain declared `final`: a project extends a framework
 * table by subclassing the whole chain, and a `final` link makes that impossible at the
 * one layer it sits on. Half two is a class of the chain building a row by name —
 * `ObjectX::create()`, `ObjectX::fromEntity()`, `EntityX::get()`, `getById()`,
 * `getAll()`, `getEmpty()`, `fromRow()`, or `new` of either. An inherited search that
 * names the framework class hands a subclass the base object: `Objects::offsetSet()`
 * drops it in silence, `DbCollection::createDbItem()` refuses it, and the project's
 * column never reaches the object. The road is `static::OBJECT_CLASS` for the object
 * and `static::entityClass()` for the Entity behind it.
 *
 * The zone is the file's declared namespace, not its path: the eight namespaces of the
 * chain are read off the base class of each layer, so this rule writes down neither a
 * directory nor a namespace — the single-source test over the framework Entity catalog
 * refuses both spellings. A demo declares its chain in the demo's own namespace and is
 * outside the zone by construction: the lowest classes of a project's chain may be
 * `final`, and a project builds its own chain by name as it likes. A fixture is judged
 * exactly as a framework file is, by declaring one of the eight namespaces.
 *
 * A name is resolved the way PHP resolves it — through this file's `use` imports, else
 * against its namespace — so an alias such as `ObjectIdentity` is followed to the class
 * it imports, and a bare name written in a chain namespace reaches its sibling. `self`,
 * `static` and `parent` are not names of a class and are never a hit; a method wearing
 * one of the judged names on any other class is not one either. Only real tokens are
 * read, so either spelling inside a comment or a string is silent. A group import
 * (`use A\{B, C}`) is not followed: the tree does not write one, and a name imported
 * that way resolves against the namespace instead, which is silent rather than wrong.
 *
 * Reading a constant off a framework class — `EntityX::_table`, a column name — stays
 * legal: the table and its columns are the subclass's too, and no row is built. So does
 * `EntityX::countUpTo()`, which answers a number and not a row.
 */
final class FrameworkOrmChainRule implements CodeStyleRule
{
    public const string ID = 'ORM-CHAIN-OPEN';

    private const string DOC = 'docs/agents/orm/inheritance.md';

    /** Names that answer to the enclosing class rather than to a class of their own. */
    private const array SELF_REFERENCES = ['self', 'static', 'parent'];

    /**
     * Factories of the object layer, judged when called by name on a framework Object item.
     *
     * @var array<int, string>
     */
    private const array OBJECT_FACTORIES = ['create', 'fromEntity'];

    /**
     * Readers and factories of the entity layer, judged when called by name on a framework Entity.
     *
     * @var array<int, string>
     */
    private const array ENTITY_FACTORIES = ['get', 'getById', 'getAll', 'getEmpty', 'fromRow'];

    /**
     * Token types a name reaches the rule as, fully qualified spellings included.
     *
     * @var array<int, int>
     */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * Operators that make whatever follows them a member of something, never a class name.
     *
     * @var array<int, int>
     */
    private const array MEMBER_ACCESS_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /**
     * Tokens that open a declaration; every `use` before the first of them is an import.
     *
     * @var array<int, int>
     */
    private const array DECLARATION_OPENERS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_FN];

    /**
     * The eight namespaces of the chain, lower-cased, read off one base class of each layer.
     *
     * @var array<int, string>
     */
    private readonly array $chainNamespaces;

    /** The Object item namespace, lower-cased: the classes whose factories half two judges. */
    private readonly string $objectItemNamespace;

    /** The Entity item namespace, lower-cased: the classes whose readers half two judges. */
    private readonly string $entityItemNamespace;

    public function __construct()
    {
        $this->objectItemNamespace = self::namespaceOf(Object_::class);
        $this->entityItemNamespace = self::namespaceOf(Entity::class);
        $this->chainNamespaces = array_map(self::namespaceOf(...), [
            Entity::class,
            EntityCollection::class,
            Object_::class,
            ObjectCollection::class,
            DbItem::class,
            DbCollection::class,
            ItemDbActions::class,
            CollectionDbActions::class,
        ]);
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
        $namespace = $this->declaredNamespace($tokens);
        if ($namespace === null || !in_array(strtolower($namespace), $this->chainNamespaces, true)) {
            return;
        }

        $imports = $this->imports($tokens);
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_FINAL) {
                yield from $this->finalClass($relativePath, $tokens, $index);
            } elseif ($token[0] === T_NEW) {
                yield from $this->construction($relativePath, $tokens, $index, $namespace, $imports);
            } elseif (in_array($token[0], self::NAME_TOKENS, true)) {
                yield from $this->staticFactory($relativePath, $tokens, $index, $namespace, $imports);
            }
        }
    }

    /**
     * Half one: `final` in front of a class declaration. A `final` method is not judged —
     * the base classes seal a constructor and a clone that way, and the rule is about
     * whether the class can be subclassed at all.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `final` keyword
     * @return iterable<Violation> One entry when the keyword seals a class
     */
    private function finalClass(string $relativePath, array $tokens, int $index): iterable
    {
        $cursor = $this->significantIndex($tokens, $index, 1);
        if ($cursor !== null && is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_READONLY) {
            $cursor = $this->significantIndex($tokens, $cursor, 1);
        }

        if ($cursor === null || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_CLASS) {
            return;
        }

        $name = $this->significantToken($tokens, $cursor, 1);
        if (!is_array($name) || $name[0] !== T_STRING) {
            return;
        }

        yield new Violation(
            self::ID,
            $relativePath,
            $tokens[$index][2],
            $name[1] . ' is final; a framework ORM class is designed for inheritance, and a project extends'
                . ' its table by subclassing the whole chain — drop final',
        );
    }

    /**
     * Half two, the `new` spelling: `new EntityX()` or `new ObjectX()` of a framework class.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the `new` keyword
     * @param string $namespace Namespace this file declares
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return iterable<Violation> One entry when the class built is a framework chain class
     */
    private function construction(
        string $relativePath,
        array $tokens,
        int $index,
        string $namespace,
        array $imports,
    ): iterable {
        $name = $this->significantToken($tokens, $index, 1);
        if (!is_array($name) || !$this->isClassName($name)) {
            return;
        }

        $class = $this->resolve($name, $namespace, $imports);
        $classNamespace = self::namespaceOf($class);
        if ($classNamespace !== $this->objectItemNamespace && $classNamespace !== $this->entityItemNamespace) {
            return;
        }

        yield new Violation(self::ID, $relativePath, $name[2], 'new ' . $name[1] . '() ' . $this->buildsByName());
    }

    /**
     * Half two, the static spelling: a judged factory called on a framework class named
     * in front of the `::`. The name is read only where it stands on its own — after a
     * `::` or an arrow it is a constant or a member, which is exactly how
     * `static::OBJECT_CLASS::create()` reads.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index of the name token
     * @param string $namespace Namespace this file declares
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return iterable<Violation> One entry when a chain factory is called on a framework class by name
     */
    private function staticFactory(
        string $relativePath,
        array $tokens,
        int $index,
        string $namespace,
        array $imports,
    ): iterable {
        $name = $tokens[$index];
        if (!is_array($name) || !$this->isClassName($name)) {
            return;
        }

        $previous = $this->significantToken($tokens, $index, -1);
        if (is_array($previous) && in_array($previous[0], self::MEMBER_ACCESS_OPERATORS, true)) {
            return;
        }

        $operator = $this->significantIndex($tokens, $index, 1);
        if ($operator === null || !is_array($tokens[$operator]) || $tokens[$operator][0] !== T_DOUBLE_COLON) {
            return;
        }

        $method = $this->significantIndex($tokens, $operator, 1);
        if ($method === null || !is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
            return;
        }

        if ($this->significantToken($tokens, $method, 1) !== '(') {
            return;
        }

        $classNamespace = self::namespaceOf($this->resolve($name, $namespace, $imports));
        $judged = match ($classNamespace) {
            $this->objectItemNamespace => self::OBJECT_FACTORIES,
            $this->entityItemNamespace => self::ENTITY_FACTORIES,
            default => [],
        };
        if (!in_array($tokens[$method][1], $judged, true)) {
            return;
        }

        yield new Violation(
            self::ID,
            $relativePath,
            $name[2],
            $name[1] . '::' . $tokens[$method][1] . '() ' . $this->buildsByName(),
        );
    }

    /**
     * @return string The half-two message after the spelling that was hit
     */
    private function buildsByName(): string
    {
        return 'builds a framework chain class by name; build through the link constants — static::OBJECT_CLASS,'
            . ' static::entityClass() — so a subclass mounted under the framework key is what gets built';
    }

    /**
     * @param array{0: int, 1: string, 2: int} $token Name token
     * @return bool True when the token names a class of its own rather than the enclosing one
     */
    private function isClassName(array $token): bool
    {
        return in_array($token[0], self::NAME_TOKENS, true)
            && !in_array(strtolower($token[1]), self::SELF_REFERENCES, true);
    }

    /**
     * Resolves a name the way PHP does: a fully qualified spelling as written, a
     * qualified one through the import its first segment names, else against the
     * namespace, and a bare one through its import, else against the namespace.
     *
     * @param array{0: int, 1: string, 2: int} $token Name token
     * @param string $namespace Namespace this file declares
     * @param array<string, string> $imports Imported names, lower-cased alias => class
     * @return string Fully qualified class name, without a leading separator
     */
    private function resolve(array $token, string $namespace, array $imports): string
    {
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($token[1], '\\');
        }

        if ($token[0] === T_NAME_QUALIFIED) {
            [$head, $rest] = explode('\\', $token[1], 2);
            $imported = $imports[strtolower($head)] ?? null;

            return $imported === null ? $namespace . '\\' . $token[1] : $imported . '\\' . $rest;
        }

        return $imports[strtolower($token[1])] ?? $namespace . '\\' . $token[1];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return string|null Namespace the file declares, or null when it declares none
     */
    private function declaredNamespace(array $tokens): ?string
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $name = $this->significantToken($tokens, $index, 1);

            return is_array($name) && in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true) ? $name[1] : null;
        }

        return null;
    }

    /**
     * The file's imports, read up to the first declaration: a `use` past that point is
     * a trait or a closure capture. A function or constant import names no class and adds
     * nothing; the walk steps over the whole statement either way.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<string, string> Imported names, lower-cased alias => fully qualified class
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        for ($index = 0; isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], self::DECLARATION_OPENERS, true)) {
                break;
            }

            if ($token[0] !== T_USE) {
                continue;
            }

            $cursor = $this->significantIndex($tokens, $index, 1);
            while ($cursor !== null && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], self::NAME_TOKENS, true)) {
                $class = ltrim($tokens[$cursor][1], '\\');
                $alias = substr((string)strrchr('\\' . $class, '\\'), 1);

                $next = $this->significantIndex($tokens, $cursor, 1);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_AS) {
                    $aliasIndex = $this->significantIndex($tokens, $next, 1);
                    if ($aliasIndex === null || !is_array($tokens[$aliasIndex])) {
                        break;
                    }

                    $alias = $tokens[$aliasIndex][1];
                    $next = $this->significantIndex($tokens, $aliasIndex, 1);
                }

                $imports[strtolower($alias)] = $class;
                if ($next === null || $tokens[$next] !== ',') {
                    break;
                }

                $cursor = $this->significantIndex($tokens, $next, 1);
            }

            $index = $this->statementEnd($tokens, $index);
        }

        return $imports;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index inside the statement
     * @return int Index of the semicolon that ends it, or one past the last token when the file ends first
     */
    private function statementEnd(array $tokens, int $index): int
    {
        for ($cursor = $index; isset($tokens[$cursor]); $cursor++) {
            if ($tokens[$cursor] === ';') {
                return $cursor;
            }
        }

        return $cursor;
    }

    /**
     * @param string $class Fully qualified class name
     * @return string Its namespace, lower-cased; for a class of the global namespace the empty prefix, which is
     *     what that namespace is
     */
    private static function namespaceOf(string $class): string
    {
        return strtolower(substr($class, 0, (int)strrpos($class, '\\')));
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return string|array{0: int, 1: string, 2: int}|null Nearest token that is not whitespace or a comment
     */
    private function significantToken(array $tokens, int $index, int $step): string|array|null
    {
        $found = $this->significantIndex($tokens, $index, $step);

        return $found === null ? null : $tokens[$found];
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @param int $index Index to walk away from
     * @param int $step Direction to walk in
     * @return int|null Index of the nearest token that is not whitespace or a comment
     */
    private function significantIndex(array $tokens, int $index, int $step): ?int
    {
        for ($cursor = $index + $step; isset($tokens[$cursor]); $cursor += $step) {
            $token = $tokens[$cursor];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $cursor;
            }
        }

        return null;
    }
}

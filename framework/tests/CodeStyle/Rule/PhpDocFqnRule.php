<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
use Hilos\Tests\CodeStyle\NeighbourDeclarations;
use Hilos\Tests\CodeStyle\Violation;

/**
 * Enforces phpdoc.md rules 9 and 12: a docblock references a class by its imported
 * short name, never by a leading-backslash fully qualified name. A generic base named
 * by `@extends` or `@implements` is a type position like any other, arguments included.
 *
 * Only doc comments are read, so a leading backslash in real code, in a line
 * comment, or in a string literal is out of scope by construction.
 */
final class PhpDocFqnRule implements CodeStyleRule
{
    public const string ID = 'PHPDOC-FQN';

    private const string DOC = 'docs/agents/code-style/phpdoc.md';

    /** Tags whose type position must carry an imported short name. */
    private const array TYPE_TAGS = [
        'throws', 'param', 'return', 'var', 'property-read', 'property', 'method', 'extends', 'implements',
    ];

    /** Matches one leading-backslash class reference; each match counts as its own violation. */
    private const string FQN_PATTERN = '/(?<![\w\\\\])\\\\([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/';

    /** Brackets a type expression opens: a generic list, an array shape, a signature, an array suffix. */
    private const string TYPE_OPENING_BRACKETS = '<{([';

    /** Their closing counterparts, in the same order. */
    private const string TYPE_CLOSING_BRACKETS = '>})]';

    /**
     * How a cross-reference to a symbol of the current namespace is spelled. A head
     * that starts lower case or with a sigil names a method, a property or `self`,
     * none of which is a name an import could carry.
     */
    private const string REFERENCED_SYMBOL = '/^[A-Z][A-Za-z0-9_]*$/';

    /** The head of a reference: everything before the member, the signature or the description. */
    private const string REFERENCE_HEAD = '/^[^\s:(#]+/';

    /**
     * Keywords that put a name into the file's own reach — an import, a declaration,
     * a constant, an enum case. A `switch` label reaches the list through `case` too,
     * and lets through a reference the rule would otherwise report; that is the safe
     * side of the trade, and the alternative is tracking enum bodies for one word.
     *
     * @var array<int, int>
     */
    private const array NAMING_KEYWORDS = [T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_CONST, T_CASE];

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
     * @return iterable<Violation> One entry per leading-backslash reference
     */
    public function check(string $relativePath, array $tokens): iterable
    {
        $known = $this->knownNames($tokens);

        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }

            yield from $this->checkDocBlock($relativePath, $token[1], $token[2], $known);
        }
    }

    /**
     * Reads the docblock line by line so a hit reports the line it sits on, not the
     * line the docblock starts at.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param string $docBlock Raw doc comment text
     * @param int $startLine Line the doc comment token starts at
     * @param array<string, true> $known Lowercased names the file imports, declares, or owns as a constant
     * @return iterable<Violation> Hits inside this docblock
     */
    private function checkDocBlock(string $relativePath, string $docBlock, int $startLine, array $known): iterable
    {
        foreach (explode("\n", $docBlock) as $offset => $text) {
            $line = $startLine + $offset;

            if (preg_match('/@(' . implode('|', self::TYPE_TAGS) . ')\b(.*)$/', $text, $matches) === 1) {
                foreach ($this->fqnNames($this->typeExpression($matches[1], $matches[2])) as $name) {
                    yield new Violation(
                        self::ID,
                        $relativePath,
                        $line,
                        sprintf('@%s references \\%s instead of an imported short name', $matches[1], $name),
                    );
                }
            }

            if (preg_match_all('/\{@(?:see|link)\s+([^}]*)}/', $text, $references) === 0) {
                continue;
            }

            foreach ($references[1] as $reference) {
                $written = $this->fqnNames($reference);
                foreach ($written as $name) {
                    yield new Violation(
                        self::ID,
                        $relativePath,
                        $line,
                        sprintf('{@see} references \\%s instead of an imported short name', $name),
                    );
                }

                $message = $written === [] ? $this->judgeReference($reference, $relativePath, $known) : null;
                if ($message !== null) {
                    yield new Violation(self::ID, $relativePath, $line, $message);
                }
            }
        }
    }

    /**
     * Cuts the type position out of a tag line, so a leading backslash inside the
     * trailing description is not mistaken for a type reference.
     *
     * @param string $tag Tag name without the at sign
     * @param string $rest Everything after the tag name
     * @return string Type expression of the tag
     */
    private function typeExpression(string $tag, string $rest): string
    {
        $rest = trim((string)preg_replace('/\s+/', ' ', $rest));
        $signatureEnd = strrpos($rest, ')');

        return match ($tag) {
            'throws', 'return' => $this->typeBeforeDescription($rest),
            'method' => $signatureEnd === false ? $rest : substr($rest, 0, $signatureEnd + 1),
            // A generic argument list carries spaces after its commas, so the whole rest is the
            // type: cutting at the first space would read `Foo<A,` and miss every later argument.
            'extends', 'implements' => $rest,
            default => $this->typeBeforeVariable($rest),
        };
    }

    /**
     * @param string $rest Everything after a `@param`, `@var`, or `@property*` tag name
     * @return string Type expression standing before the documented variable
     */
    private function typeBeforeVariable(string $rest): string
    {
        $variable = strpos($rest, '$');

        return $variable === false ? $this->typeBeforeDescription($rest) : substr($rest, 0, $variable);
    }

    /**
     * Walks the expression counting the brackets a type may carry, so a compound type
     * is read whole: `array{0: Socket, 1: Socket}` carries a space after its comma,
     * and a cut at the first space would see the first entry and miss every later one.
     * Only a space standing outside every bracket ends the type and opens the
     * description, which is also where an unbalanced `>` of a prose comparison sits.
     *
     * @param string $rest Tag text with its whitespace already collapsed to single spaces
     * @return string Type expression standing before the description
     */
    private function typeBeforeDescription(string $rest): string
    {
        $depth = 0;
        for ($offset = 0; $offset < strlen($rest); $offset++) {
            $character = $rest[$offset];
            if (str_contains(self::TYPE_OPENING_BRACKETS, $character)) {
                $depth++;
            } elseif (str_contains(self::TYPE_CLOSING_BRACKETS, $character)) {
                $depth--;
            } elseif ($character === ' ' && $depth <= 0) {
                return substr($rest, 0, $offset);
            }
        }

        return $rest;
    }

    /**
     * @param string $expression Doc text to read class references from
     * @return array<int, string> Fully qualified names written with a leading backslash
     */
    private function fqnNames(string $expression): array
    {
        return preg_match_all(self::FQN_PATTERN, $expression, $matches) === 0 ? [] : $matches[1];
    }

    /**
     * The two spellings a cross-reference goes wrong in once the leading backslash
     * is gone. A partially qualified name reads against whatever namespace the file
     * happens to declare, so the same text points somewhere else the day the class
     * moves; a short name with no import points at nothing an IDE or a reader can
     * follow, and the docblock is the only place that failure never surfaces.
     *
     * @param string $reference Text between the braces of a `{@see}` or `{@link}`
     * @param string $relativePath File path relative to the scanned root
     * @param array<string, true> $known Lowercased names the file imports, declares, or owns as a constant
     * @return string|null Message of the hit, null when the reference is legal
     */
    private function judgeReference(string $reference, string $relativePath, array $known): ?string
    {
        if (preg_match(self::REFERENCE_HEAD, trim($reference), $matches) !== 1) {
            return null;
        }

        $head = $matches[0];
        if (str_contains($head, '\\')) {
            return sprintf('{@see} references %s relative to the current namespace', $head);
        }
        if (preg_match(self::REFERENCED_SYMBOL, $head) !== 1 || isset($known[strtolower($head)])) {
            return null;
        }
        // A constant of the global namespace is reached without an import by the same
        // fallback that lets code write JSON_THROW_ON_ERROR inside a namespace.
        if (defined($head) || $this->neighbours->declares($relativePath, $head)) {
            return null;
        }

        return sprintf('{@see} references %s, which is neither imported nor declared in this namespace', $head);
    }

    /**
     * Everything the file answers for on its own, read in one pass before any
     * docblock is judged: what it imports, what it declares, and the constants and
     * enum cases a reference may point at by their bare name.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $tokens Raw token_get_all() output
     * @return array<string, true> Lowercased names the file resolves without an import
     */
    private function knownNames(array $tokens): array
    {
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

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
            if (in_array($token[0], self::NAMING_KEYWORDS, true) && ($token[0] !== T_USE || $depth === 0)) {
                foreach ($this->namesDeclaredAt($significant, $index) as $name) {
                    $known[strtolower($name)] = true;
                }
            }
        }

        return $known;
    }

    /**
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the keyword that names something
     * @return array<int, string> Names this keyword brings into the file
     */
    private function namesDeclaredAt(array $significant, int $index): array
    {
        $token = $significant[$index];
        if (is_array($token) && $token[0] === T_USE) {
            return $this->importedNames($significant, $index);
        }
        if (is_array($token) && $token[0] === T_CONST) {
            return $this->constantNames($significant, $index);
        }

        // An enum case may be named with a reserved word — `case DEFAULT` reaches the
        // rule as T_DEFAULT — so the name is recognized by its spelling, not its type.
        $name = $significant[$index + 1] ?? null;

        return is_array($name) && preg_match(self::REFERENCED_SYMBOL, $name[1]) === 1 ? [$name[1]] : [];
    }

    /**
     * One `const` keyword declares any number of names, and a typed constant puts its
     * type where a reader expects the name. Whatever stands directly in front of an
     * `=` at the top level of the declaration is the name — which also covers a
     * constant named with a reserved word, handed over as `T_DEFAULT` rather than as
     * a plain string.
     *
     * @param array<int, string|array{0: int, 1: string, 2: int}> $significant Stream without ignorable tokens
     * @param int $index Position of the `const` keyword
     * @return array<int, string> Names this declaration brings into the file
     */
    private function constantNames(array $significant, int $index): array
    {
        $names = [];
        $depth = 0;

        for ($offset = $index + 1; isset($significant[$offset]); $offset++) {
            $token = $significant[$offset];

            if ($token === '(' || $token === '[') {
                $depth++;
                continue;
            }
            if ($token === ')' || $token === ']') {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($token === ';') {
                break;
            }
            $name = $token === '=' ? $significant[$offset - 1] ?? null : null;
            if (is_array($name)) {
                $names[] = $name[1];
            }
        }

        return $names;
    }

    /**
     * Reads one `use` statement into the names it makes available; a grouped import
     * is read by the same walk, its prefix overwritten by each name in the braces.
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
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $segments = explode('\\', trim($token[1], '\\'));
                $current = $renaming ? $token[1] : (string)end($segments);
                $renaming = false;
            }
        }

        return $current === null ? $aliases : [...$aliases, $current];
    }
}

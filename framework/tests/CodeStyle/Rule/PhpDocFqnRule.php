<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Rule;

use Hilos\Tests\CodeStyle\CodeStyleRule;
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
        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }

            yield from $this->checkDocBlock($relativePath, $token[1], $token[2]);
        }
    }

    /**
     * Reads the docblock line by line so a hit reports the line it sits on, not the
     * line the docblock starts at.
     *
     * @param string $relativePath File path relative to the scanned root
     * @param string $docBlock Raw doc comment text
     * @param int $startLine Line the doc comment token starts at
     * @return iterable<Violation> Hits inside this docblock
     */
    private function checkDocBlock(string $relativePath, string $docBlock, int $startLine): iterable
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
                foreach ($this->fqnNames($reference) as $name) {
                    yield new Violation(
                        self::ID,
                        $relativePath,
                        $line,
                        sprintf('{@see} references \\%s instead of an imported short name', $name),
                    );
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
            'throws', 'return' => explode(' ', $rest, 2)[0],
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

        return $variable === false ? explode(' ', $rest, 2)[0] : substr($rest, 0, $variable);
    }

    /**
     * @param string $expression Doc text to read class references from
     * @return array<int, string> Fully qualified names written with a leading backslash
     */
    private function fqnNames(string $expression): array
    {
        return preg_match_all(self::FQN_PATTERN, $expression, $matches) === 0 ? [] : $matches[1];
    }
}

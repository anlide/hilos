<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/** Cursor over one bounded DDL or session statement, never over table data. */
final class SqlTokens
{
    private int $position = 0;

    /**
     * @param list<SqlToken> $tokens Statement tokens without its delimiter
     */
    public function __construct(private readonly array $tokens)
    {
    }

    /**
     * @return bool Whether every token has been consumed
     */
    public function ended(): bool
    {
        return $this->position === count($this->tokens);
    }

    /**
     * @param string $text Keyword or punctuation
     * @return bool Whether the next token matches
     */
    public function is(string $text): bool
    {
        return ($this->tokens[$this->position] ?? null)?->is($text) ?? false;
    }

    /**
     * @param string $text Optional keyword or punctuation
     * @return ?SqlToken Consumed token, or null when the optional token is absent
     */
    public function takeIf(string $text): ?SqlToken
    {
        if (!$this->is($text)) {
            return null;
        }
        return $this->tokens[$this->position++];
    }

    /**
     * @param string $text Required keyword or punctuation
     * @throws RestoreFailedException When the grammar does not match
     */
    public function expect(string $text): void
    {
        if (!$this->takeIf($text)) {
            throw new RestoreFailedException("Unsupported dump syntax: expected {$text}");
        }
    }

    /**
     * @return SqlToken Next required token
     * @throws RestoreFailedException When a required token is absent
     */
    public function take(): SqlToken
    {
        if ($this->ended()) {
            throw new RestoreFailedException('Incomplete dump statement');
        }
        return $this->tokens[$this->position++];
    }

    /**
     * @return string Unqualified identifier, with doubled backticks decoded
     * @throws RestoreFailedException When the next token is not a complete identifier
     */
    public function name(): string
    {
        $token = $this->take();
        if (!$token->complete || !in_array($token->kind, [SqlToken::WORD, SqlToken::IDENTIFIER], true)
            || $token->value === '' || str_contains($token->value, "\0")) {
            throw new RestoreFailedException('Unsupported or incomplete dump identifier');
        }
        return $token->value;
    }

    /**
     * @return list<string> Parenthesized identifier list
     * @throws RestoreFailedException When the column list is not readable
     */
    public function names(): array
    {
        $this->expect('(');
        $names = [$this->name()];
        while ($this->takeIf(',')) {
            $names[] = $this->name();
        }
        $this->expect(')');
        return $names;
    }

    /**
     * @return list<SqlToken> Balanced parenthesized body without the outer delimiters
     * @throws RestoreFailedException When the group is incomplete
     */
    public function group(): array
    {
        $this->expect('(');
        $depth = 1;
        $body = [];
        while (!$this->ended()) {
            $token = $this->take();
            if ($token->is('(')) {
                $depth++;
            } elseif ($token->is(')')) {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }
            $body[] = $token;
        }
        throw new RestoreFailedException('Incomplete parenthesized dump definition');
    }

    /**
     * @return list<SqlToken> Remaining tokens, consuming this cursor
     */
    public function consumeRest(): array
    {
        $rest = array_slice($this->tokens, $this->position);
        $this->position = count($this->tokens);
        return $rest;
    }

    /**
     * @param list<SqlToken> $tokens Comma-separated definitions or assignments
     * @return list<list<SqlToken>> Groups split only at the outermost commas
     * @throws RestoreFailedException When a group is empty or parentheses are unbalanced
     */
    public static function split(array $tokens): array
    {
        $groups = [];
        $group = [];
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token->is('(')) {
                $depth++;
            } elseif ($token->is(')')) {
                $depth--;
            }
            if ($depth < 0) {
                throw new RestoreFailedException('Contradictory dump parentheses');
            }
            if ($token->is(',') && $depth === 0) {
                if ($group === []) {
                    throw new RestoreFailedException('Incomplete dump definition');
                }
                $groups[] = $group;
                $group = [];
            } else {
                $group[] = $token;
            }
        }
        if ($depth !== 0 || $group === []) {
            throw new RestoreFailedException('Incomplete dump definition');
        }
        $groups[] = $group;
        return $groups;
    }
}

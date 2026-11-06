<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Backup\Exception\RestoreFailedException;

/** Fixed-size input buffer, independent of line, statement and INSERT size. */
final class DumpSqlLexer
{
    public const int CHUNK_BYTES = 65536;

    private const int TOKEN_BYTES = 512;
    private const int ASCII_BYTE_LIMIT = 128;
    private const int MIN_VERSION_DIGITS = 5;
    private const int MAX_VERSION_DIGITS = 6;

    private string $buffer = '';
    private int $position = 0;
    private int $base = 0;
    private bool $executableComment = false;
    private bool $dumpCompleted = false;
    private bool $bufferOnly = false;
    private bool $hasExecutableComment = false;
    private bool $hasStringEscape = false;
    private bool $sqlModeKnown = false;

    /**
     * @param resource|null $handle Pinned extracted dump; null only for the in-memory factory
     * @param ?DumpServerVersion $server Destination grammar for dump admission; null for conservative migration analysis
     */
    public function __construct(private readonly mixed $handle, private readonly ?DumpServerVersion $server = null)
    {
    }

    /**
     * @param string $sql One migration execution unit, already read into memory by the migrator
     * @return self The same lexical grammar over an in-memory source
     */
    public static function fromSql(string $sql): self
    {
        $lexer = new self(null);
        $lexer->buffer = $sql;
        $lexer->bufferOnly = true;
        return $lexer;
    }

    /**
     * @return bool Whether conditional executable comments occurred outside quoted text
     */
    public function hasExecutableComment(): bool
    {
        return $this->hasExecutableComment;
    }

    /**
     * @return bool Whether quoted text used backslashes whose meaning depends on NO_BACKSLASH_ESCAPES
     */
    public function hasStringEscape(): bool
    {
        return $this->hasStringEscape;
    }

    /** @param bool $known Whether a retained SET established the admitted quote mode in the executable stream */
    public function setSqlModeKnown(bool $known): void
    {
        $this->sqlModeKnown = $known;
    }

    /**
     * @return ?SqlToken Next token, or null only at a lexically complete EOF
     * @throws RestoreFailedException When input breaks or a lexical form is ambiguous
     */
    public function next(): ?SqlToken
    {
        while (($char = $this->peek()) !== null) {
            if (ctype_space($char)) {
                $this->position++;
                continue;
            }
            if ($char === '*' && $this->peek(1) === '/' && $this->executableComment) {
                $this->position += 2;
                $this->executableComment = false;
                continue;
            }
            if ($char === '#' || ($char === '-' && $this->peek(1) === '-'
                && ($this->peek(2) === null || ctype_space($this->peek(2)) || ctype_cntrl($this->peek(2))))) {
                if ($this->executableComment) {
                    throw new RestoreFailedException('Unsupported line comment inside an executable dump comment');
                }
                $comment = '';
                $complete = true;
                while (($char = $this->peek()) !== null && $char !== "\n" && $char !== "\r") {
                    $this->retain($comment, $char, $complete);
                    $this->position++;
                }
                if ($complete && preg_match('/^-- Dump completed(?: on [^\r\n]+)?\s*$/D', $comment) === 1) {
                    if ($this->dumpCompleted) {
                        throw new RestoreFailedException('Contradictory duplicate mysqldump completion comment');
                    }
                    $this->dumpCompleted = true;
                }
                continue;
            }
            if ($char === '/' && $this->peek(1) === '*') {
                $this->openComment();
                continue;
            }

            if ($this->dumpCompleted) {
                throw new RestoreFailedException('Contradictory SQL after the mysqldump completion comment');
            }
            $start = $this->offset();
            if ($char === "'" || $char === '"' || $char === '`') {
                return $this->quoted($char, $start);
            }
            if (ctype_alnum($char) || $char === '_' || $char === '$' || ord($char) >= self::ASCII_BYTE_LIMIT) {
                $value = '';
                $complete = true;
                do {
                    $this->retain($value, $char, $complete);
                    $this->position++;
                    $char = $this->peek();
                } while ($char !== null
                    && (ctype_alnum($char) || $char === '_' || $char === '$' || ord($char) >= self::ASCII_BYTE_LIMIT));

                return new SqlToken(SqlToken::WORD, $value, $start, $this->offset(), $complete);
            }
            if ($char === ';' && $this->executableComment) {
                throw new RestoreFailedException('Unsupported statement boundary inside an executable dump comment');
            }
            $this->position++;

            return new SqlToken(SqlToken::SYMBOL, $char, $start, $this->offset());
        }

        if ($this->executableComment) {
            throw new RestoreFailedException('Incomplete executable dump comment');
        }

        return null;
    }

    /**
     * @return int Current absolute byte offset
     */
    public function offset(): int
    {
        return $this->base + $this->position;
    }

    /**
     * @return bool Whether a genuine line comment closed the mysqldump stream
     */
    public function dumpCompleted(): bool
    {
        return $this->dumpCompleted;
    }

    /**
     * @throws RestoreFailedException When a comment is incomplete or its executable form is unsupported
     */
    private function openComment(): void
    {
        if ($this->executableComment) {
            throw new RestoreFailedException('Unsupported nested executable dump comment');
        }
        $this->position += 2;
        $executable = $this->peek() === '!';
        $mariaDbOnly = false;
        if ($this->peek() === 'M' && $this->peek(1) === '!') {
            $this->position++;
            $executable = true;
            $mariaDbOnly = true;
        }
        if ($executable) {
            $this->hasExecutableComment = true;
            if ($this->dumpCompleted) {
                throw new RestoreFailedException('Contradictory executable comment after mysqldump completion');
            }
            $this->position++;
            $digits = 0;
            $version = '';
            while (($char = $this->peek()) !== null && ctype_digit($char)) {
                $digits++;
                if ($digits > self::MAX_VERSION_DIGITS) {
                    throw new RestoreFailedException('Unsupported executable dump comment version');
                }
                $version .= $char;
                $this->position++;
            }
            if ($digits !== 0 && ($digits < self::MIN_VERSION_DIGITS || $digits > self::MAX_VERSION_DIGITS
                || ($this->peek() !== null && !ctype_space($this->peek()) && $this->peek() !== '\\'))) {
                throw new RestoreFailedException('Unsupported executable dump comment version');
            }
            // MariaDB's dump client directive is not server SQL.
            if ($this->peek() === '\\') {
                $directive = $this->commentBody();
                if ($directive !== '\\- enable the sandbox mode ') {
                    throw new RestoreFailedException('Unsupported dump client directive');
                }
                return;
            }
            $this->server?->assertExecutes($mariaDbOnly, (int)$version);
            $this->executableComment = true;
            return;
        }

        $this->commentBody();
    }

    /**
     * @return string Bounded comment prefix, sufficient for the sandbox directive
     * @throws RestoreFailedException When the comment does not close
     */
    private function commentBody(): string
    {
        $body = '';
        $complete = true;
        while (($char = $this->peek()) !== null) {
            if ($char === '*' && $this->peek(1) === '/') {
                $this->position += 2;
                return $body;
            }
            $this->retain($body, $char, $complete);
            $this->position++;
        }

        throw new RestoreFailedException('Incomplete dump comment');
    }

    /**
     * @param string $quote Opening delimiter
     * @param int $start Absolute opening offset
     * @return SqlToken Decoded bounded token
     * @throws RestoreFailedException When quoted text is incomplete, ambiguous or a legacy physical identifier
     */
    private function quoted(string $quote, int $start): SqlToken
    {
        if ($this->server !== null && !$this->sqlModeKnown && $quote === '"') {
            throw new RestoreFailedException('Unknown SQL mode for double quotes in full dump');
        }
        $this->position++;
        $value = '';
        $complete = true;
        while (($char = $this->peek()) !== null) {
            if ($this->executableComment && $char === '*' && $this->peek(1) === '/') {
                throw new RestoreFailedException('Ambiguous quote inside an executable dump comment');
            }
            $this->position++;
            if ($char === $quote) {
                if ($this->peek() !== $quote) {
                    if ($quote === '`' && str_starts_with(strtolower($value), '#mysql50#')) {
                        throw new RestoreFailedException('Unsupported legacy physical identifier in full dump');
                    }
                    return new SqlToken(
                        $quote === '`' ? SqlToken::IDENTIFIER : SqlToken::STRING,
                        $value,
                        $start,
                        $this->offset(),
                        $complete,
                    );
                }
                $this->position++;
            } elseif ($char === '\\' && $quote !== '`') {
                if ($this->server !== null && !$this->sqlModeKnown) {
                    throw new RestoreFailedException('Unknown SQL mode for backslash escaping in full dump');
                }
                $this->hasStringEscape = true;
                $char = $this->peek();
                if ($char === null) {
                    break;
                }
                $this->position++;
                $char = match ($char) {
                    '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                    default => $char,
                };
            }
            $this->retain($value, $char, $complete);
        }

        throw new RestoreFailedException('Incomplete quoted value in dump');
    }

    /**
     * @param string $value Bounded token prefix
     * @param string $char Next decoded byte
     * @param bool $complete Whether every byte still fits
     */
    private function retain(string &$value, string $char, bool &$complete): void
    {
        if (strlen($value) < self::TOKEN_BYTES) {
            $value .= $char;
        } else {
            $complete = false;
        }
    }

    /**
     * @param int $ahead Bytes of lookahead
     * @return ?string Byte at the cursor, or null at EOF
     * @throws RestoreFailedException When the input stream breaks
     */
    private function peek(int $ahead = 0): ?string
    {
        while ($this->position + $ahead >= strlen($this->buffer)) {
            if ($this->bufferOnly || feof($this->handle)) {
                return null;
            }
            $this->buffer = substr($this->buffer, $this->position);
            $this->base += $this->position;
            $this->position = 0;
            $chunk = fread($this->handle, self::CHUNK_BYTES);
            if ($chunk === false || ($chunk === '' && !feof($this->handle))) {
                throw new RestoreFailedException('Cannot read the full restore dump to its end');
            }
            $this->buffer .= $chunk;
        }

        return $this->buffer[$this->position + $ahead];
    }
}

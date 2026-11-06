<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** A bounded token; long data literals are consumed without retaining their contents. */
final readonly class SqlToken
{
    public const string WORD = 'word';
    public const string IDENTIFIER = 'identifier';
    public const string STRING = 'string';
    public const string SYMBOL = 'symbol';

    private const array STRING_CHARSETS = ['_BINARY', '_ASCII', '_LATIN1', '_UTF8', '_UTF8MB3', '_UTF8MB4'];

    /**
     * @param string $kind Lexical token kind
     * @param string $value Decoded quoted value or literal token text
     * @param int $start Byte offset in the source file
     * @param int $end Exclusive byte offset in the source file
     * @param bool $complete Whether the bounded value contains the whole token
     */
    public function __construct(
        public string $kind,
        public string $value,
        public int $start,
        public int $end,
        public bool $complete = true,
    ) {
    }

    /**
     * Quoted identifiers and strings never act as SQL keywords.
     *
     * @param string $text Keyword or punctuation to compare
     * @return bool Whether this is that unquoted token
     */
    public function is(string $text): bool
    {
        return ($this->kind === self::WORD || $this->kind === self::SYMBOL)
            && $this->complete && strcasecmp($this->value, $text) === 0;
    }

    /**
     * @param SqlToken $string Following quoted string, possibly larger than its retained prefix
     * @return bool Whether this token introduces that string in the admitted dump grammar
     */
    public function isIntroducerFor(SqlToken $string): bool
    {
        if ($this->kind !== self::WORD || !$this->complete || $string->kind !== self::STRING) {
            return false;
        }
        if ($this->is('B') || $this->is('X')) {
            return $string->complete && preg_match($this->is('B') ? '/^[01]*$/D' : '/^(?:[0-9a-f]{2})*$/iD', $string->value) === 1;
        }
        return in_array(strtoupper($this->value), self::STRING_CHARSETS, true);
    }
}

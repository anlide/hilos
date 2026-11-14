<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * Reads one operation's logical object from its parts as they arrive, into segmented values:
 * text is written to its pages a leaf at a time and never held whole, so a part's bytes are the
 * most this reader keeps beside the pages it builds.
 *
 * The object is JSON as {@see RtSyncStreamEncoder} writes it: objects, strings, numbers and the
 * three literals. An object key that is a canonical integer is read back as an integer, as a
 * native array would hold it. Anything else - an array, a surrogate escape, a trailing byte, an
 * unfinished value - is refused rather than guessed at.
 */
final class RtSyncStreamDecoder
{
    /** @var int Deepest nesting of objects a value may have, as json_decode allows */
    private const int MAX_DEPTH = 512;
    /** @var int Longest object key held natively while it is read */
    private const int MAX_KEY_BYTES = 4096;
    /** @var int Longest number or literal */
    private const int MAX_TOKEN_BYTES = 64;
    /** @var string Bytes a number or a literal is made of */
    private const string TOKEN_BYTES = '-+.0123456789eEtruefalsn';
    /** @var string Bytes a number or a literal starts with */
    private const string TOKEN_START = '-0123456789tfn';
    /** @var string Whitespace JSON allows between tokens */
    private const string WHITESPACE = " \t\r\n";
    /** @var string Shape of a JSON number */
    private const string NUMBER_PATTERN = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/D';
    /** @var string Shape of an object key a native array holds as an integer */
    private const string INTEGER_KEY_PATTERN = '/^(0|-?[1-9][0-9]*)$/D';
    /** @var array<string, string> Single-character escapes and the bytes they stand for */
    private const array ESCAPES = [
        '"' => '"',
        '\\' => '\\',
        '/' => '/',
        'b' => "\x08",
        'f' => "\x0c",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
    ];
    /** @var int Length of a complete `\uXXXX` escape */
    private const int UNICODE_ESCAPE_BYTES = 6;
    /** @var int Last code point of one UTF-8 byte */
    private const int UTF8_ONE_BYTE = 0x7f;
    /** @var int Last code point of two UTF-8 bytes */
    private const int UTF8_TWO_BYTES = 0x7ff;
    /** @var int Mark of a UTF-8 continuation byte */
    private const int UTF8_CONTINUATION = 0x80;
    /** @var int Code point bits one UTF-8 continuation byte carries */
    private const int UTF8_CONTINUATION_BITS = 0x3f;
    /** @var int Width of those bits */
    private const int UTF8_CONTINUATION_SHIFT = 6;
    /** @var int Mark of the first of two UTF-8 bytes */
    private const int UTF8_TWO_BYTE_LEAD = 0xc0;
    /** @var int Mark of the first of three UTF-8 bytes */
    private const int UTF8_THREE_BYTE_LEAD = 0xe0;
    /** @var int First surrogate code point */
    private const int SURROGATE_FIRST = 0xd800;
    /** @var int Last surrogate code point */
    private const int SURROGATE_LAST = 0xdfff;

    private const int EXPECT_VALUE = 0;
    private const int EXPECT_KEY_OR_END = 1;
    private const int EXPECT_KEY = 2;
    private const int EXPECT_COLON = 3;
    private const int EXPECT_COMMA_OR_END = 4;
    private const int IN_STRING = 5;
    private const int IN_TOKEN = 6;
    private const int COMPLETE = 7;

    private int $state = self::EXPECT_VALUE;
    /** @var list<RtRowWriter> Objects still open, outermost first */
    private array $openObjects = [];
    /** @var list<?RtRowValues> Key each open object is reading the value of */
    private array $keys = [];
    private bool $readingKey = false;
    /** Key, token, or text bytes not yet written to a leaf */
    private string $pending = '';
    private ?RtRowWriter $text = null;
    /** Escape read so far: empty, a backslash, or the start of a unicode escape */
    private string $escape = '';
    private ?RtRowValues $object = null;
    private readonly RtRowArena $arena;

    public function __construct()
    {
        $this->arena = RtRowArena::process();
    }

    /**
     * Reads the next part's bytes.
     *
     * @param string $bytes Bytes of one part, in order
     * @return Generator<int, RtWork, int, void> Bounded reading and page preparation
     * @throws InvalidFormatException When the bytes are not the JSON this reader takes
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    public function feed(string $bytes): Generator
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            if ($this->state === self::IN_STRING) {
                $offset = yield from $this->readString($bytes, $offset);
                continue;
            }
            if ($this->state === self::IN_TOKEN) {
                $span = strspn($bytes, self::TOKEN_BYTES, $offset);
                $this->pending .= substr($bytes, $offset, $span);
                $offset += $span;
                if (strlen($this->pending) > self::MAX_TOKEN_BYTES) {
                    throw new InvalidFormatException('An RT stream number or literal is too long');
                }
                if ($offset < $length) {
                    yield from $this->endToken();
                }
                continue;
            }
            $byte = $bytes[$offset++];
            if (str_contains(self::WHITESPACE, $byte)) {
                continue;
            }
            yield from $this->readStructure($byte);
        }
    }

    /**
     * @return RtRowValues The whole logical object
     * @throws InvalidFormatException When the bytes read so far do not close one object
     */
    public function finish(): RtRowValues
    {
        if ($this->state !== self::COMPLETE || $this->object === null) {
            throw new InvalidFormatException('An RT stream operation ended before its object did');
        }

        return $this->object;
    }

    /**
     * @param string $byte One structural byte outside a string or a token
     * @return Generator<int, RtWork, int, void> The structure it opens, separates or closes
     * @throws InvalidFormatException When the byte is not allowed here
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function readStructure(string $byte): Generator
    {
        switch ($this->state) {
            case self::EXPECT_VALUE:
                if ($byte === '{') {
                    if (count($this->openObjects) >= self::MAX_DEPTH) {
                        throw new InvalidFormatException('An RT stream value nests too deep');
                    }
                    $this->openObjects[] = new RtRowWriter($this->arena, $this->openObjects === [] ? RtRowRole::Wire : RtRowRole::Transient);
                    $this->keys[] = null;
                    $this->state = self::EXPECT_KEY_OR_END;
                } elseif ($this->openObjects === []) {
                    throw new InvalidFormatException('An RT stream operation is an object');
                } elseif ($byte === '"') {
                    $this->state = self::IN_STRING;
                    $this->readingKey = false;
                    $this->text = new RtRowWriter($this->arena, RtRowRole::Transient);
                } elseif (str_contains(self::TOKEN_START, $byte)) {
                    $this->state = self::IN_TOKEN;
                    $this->pending = $byte;
                } else {
                    throw new InvalidFormatException('An RT stream value is an object, a string, a number or a literal');
                }
                return;
            case self::EXPECT_KEY_OR_END:
            case self::EXPECT_KEY:
                if ($byte === '"') {
                    $this->state = self::IN_STRING;
                    $this->readingKey = true;
                } elseif ($byte === '}' && $this->state === self::EXPECT_KEY_OR_END) {
                    yield from $this->closeObject();
                } else {
                    throw new InvalidFormatException('An RT stream object expects a key here');
                }
                return;
            case self::EXPECT_COLON:
                if ($byte !== ':') {
                    throw new InvalidFormatException('An RT stream object expects a colon after its key');
                }
                $this->state = self::EXPECT_VALUE;
                return;
            case self::EXPECT_COMMA_OR_END:
                if ($byte === ',') {
                    $this->state = self::EXPECT_KEY;
                } elseif ($byte === '}') {
                    yield from $this->closeObject();
                } else {
                    throw new InvalidFormatException('An RT stream object expects a comma or its end here');
                }
                return;
            default:
                throw new InvalidFormatException('An RT stream operation carries bytes after its object');
        }
    }

    /**
     * Reads a granted run of a string at once, escapes included; only an escape cut by the end of
     * the part is read a byte at a time.
     *
     * @param string $bytes Part being read
     * @param int $offset First unread byte, inside a string
     * @return Generator<int, RtWork, int, int> The next unread byte
     * @throws InvalidFormatException When an escape is malformed
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function readString(string $bytes, int $offset): Generator
    {
        $length = strlen($bytes);
        if ($this->escape !== '') {
            yield new RtWork();
            $this->escape .= $bytes[$offset];
            if ($this->escape === '\\u' || (strlen($this->escape) > 2 && strlen($this->escape) < self::UNICODE_ESCAPE_BYTES)) {
                return $offset + 1;
            }
            $escape = $this->escape;
            $this->escape = '';
            yield from $this->appendString(self::unescape($escape));

            return $offset + 1;
        }
        $end = $offset + (yield new RtWork(min($length - $offset, RtRowPage::LEAF_BYTES)));
        $decoded = '';
        while ($offset < $end) {
            $span = strcspn($bytes, '"\\', $offset, $end - $offset);
            $decoded .= substr($bytes, $offset, $span);
            $offset += $span;
            if ($offset >= $end) {
                break;
            }
            if ($bytes[$offset] === '"') {
                yield from $this->appendString($decoded);
                yield from $this->endString();

                return $offset + 1;
            }
            $escapeBytes = $offset + 1 < $length && $bytes[$offset + 1] === 'u' ? self::UNICODE_ESCAPE_BYTES : 2;
            if ($offset + $escapeBytes > $length) {
                yield from $this->appendString($decoded);
                $this->escape = '\\';

                return $offset + 1;
            }
            $decoded .= self::unescape(substr($bytes, $offset, $escapeBytes));
            $offset += $escapeBytes;
        }
        yield from $this->appendString($decoded);

        return $offset;
    }

    /**
     * @param string $bytes Decoded bytes of the string being read
     * @return Generator<int, RtWork, int, void> A leaf written once the bytes fill one
     * @throws InvalidFormatException When a key grows past its bound
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function appendString(string $bytes): Generator
    {
        $this->pending .= $bytes;
        if ($this->readingKey) {
            if (strlen($this->pending) > self::MAX_KEY_BYTES) {
                throw new InvalidFormatException('An RT stream object key is too long');
            }

            return;
        }
        if (strlen($this->pending) >= RtRowPage::LEAF_BYTES) {
            yield from $this->text->appendText($this->pending);
            $this->pending = '';
        }
    }

    /**
     * @return Generator<int, RtWork, int, void> The completed key or text value
     * @throws InvalidFormatException When a native key cannot be carried
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function endString(): Generator
    {
        $bytes = $this->pending;
        $this->pending = '';
        if ($this->readingKey) {
            $key = preg_match(self::INTEGER_KEY_PATTERN, $bytes) === 1 ? filter_var($bytes, FILTER_VALIDATE_INT) : false;
            $this->keys[array_key_last($this->keys)] = yield from RtRowWriter::encode(
                $key === false ? $bytes : $key,
                $this->arena,
            );
            $this->state = self::EXPECT_COLON;

            return;
        }
        $text = $this->text;
        $this->text = null;
        yield from $text->appendText($bytes);
        yield from $this->completeValue((yield from $text->finish()));
    }

    /**
     * @return Generator<int, RtWork, int, void> The completed number or literal
     * @throws InvalidFormatException When the token is no JSON number or literal
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function endToken(): Generator
    {
        $token = $this->pending;
        $this->pending = '';
        $value = match ($token) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => self::number($token),
        };
        yield from $this->completeValue((yield from RtRowWriter::encode($value, $this->arena)));
    }

    /**
     * @return Generator<int, RtWork, int, void> The closed object, written into its parent
     * @throws InvalidFormatException When a value cannot be carried
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function closeObject(): Generator
    {
        $object = array_pop($this->openObjects);
        array_pop($this->keys);
        yield from $this->completeValue((yield from $object->finish()));
    }

    /**
     * @param RtRowValues $value Completed value
     * @return Generator<int, RtWork, int, void> Its entry in the open object, or the whole object
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function completeValue(RtRowValues $value): Generator
    {
        if ($this->openObjects === []) {
            $this->object = $value;
            $this->state = self::COMPLETE;

            return;
        }
        $last = array_key_last($this->openObjects);
        yield from $this->openObjects[$last]->writeEntry($this->keys[$last], $value);
        $this->keys[$last] = null;
        $this->state = self::EXPECT_COMMA_OR_END;
    }

    /**
     * @param string $escape One whole escape, its backslash included
     * @return string The bytes it stands for
     * @throws InvalidFormatException When the escape is unknown or malformed
     */
    private static function unescape(string $escape): string
    {
        if (strlen($escape) === 2) {
            return self::ESCAPES[$escape[1]] ?? throw new InvalidFormatException('An RT stream string carries an unknown escape');
        }
        $digits = substr($escape, 2);
        if (strlen($escape) !== self::UNICODE_ESCAPE_BYTES || $escape[1] !== 'u' || !ctype_xdigit($digits)) {
            throw new InvalidFormatException('An RT stream unicode escape carries four hex digits');
        }

        return self::utf8((int)hexdec($digits));
    }

    /**
     * @param string $token Number token
     * @return int|float The number, an integer when it has neither fraction nor exponent and fits one
     * @throws InvalidFormatException When the token is no JSON number
     */
    private static function number(string $token): int|float
    {
        if (preg_match(self::NUMBER_PATTERN, $token) !== 1) {
            throw new InvalidFormatException('An RT stream value carries a malformed number or literal');
        }
        $integer = strpbrk($token, '.eE') === false ? filter_var($token, FILTER_VALIDATE_INT) : false;

        return $integer === false ? (float)$token : $integer;
    }

    /**
     * @param int $codePoint Code point of a unicode escape
     * @return string Its UTF-8 bytes
     * @throws InvalidFormatException When the code point is a surrogate, which this stream never escapes
     */
    private static function utf8(int $codePoint): string
    {
        if ($codePoint >= self::SURROGATE_FIRST && $codePoint <= self::SURROGATE_LAST) {
            throw new InvalidFormatException('An RT stream string carries no surrogate escape');
        }
        if ($codePoint <= self::UTF8_ONE_BYTE) {
            return chr($codePoint);
        }
        $last = chr(self::UTF8_CONTINUATION | ($codePoint & self::UTF8_CONTINUATION_BITS));
        $rest = $codePoint >> self::UTF8_CONTINUATION_SHIFT;
        if ($codePoint <= self::UTF8_TWO_BYTES) {
            return chr(self::UTF8_TWO_BYTE_LEAD | $rest) . $last;
        }

        return chr(self::UTF8_THREE_BYTE_LEAD | ($rest >> self::UTF8_CONTINUATION_SHIFT))
            . chr(self::UTF8_CONTINUATION | ($rest & self::UTF8_CONTINUATION_BITS)) . $last;
    }
}

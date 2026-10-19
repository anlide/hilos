<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\Constants\SocketConstants;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\Exception\Base\MessageTooLongException;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Byte framing shared by command, peer and both worker links (HIL-1527). */
final class AbstractSocketJsonFramingTest extends TestCase
{
    /**
     * @param string $input Incoming bytes
     * @param ?string $message Expected framed bytes, without the delimiter
     * @param string $tail Exact bytes left for the next call
     */
    #[DataProvider('framingCases')]
    public function testExtractsExactBytesAndPreservesTheTail(string $input, ?string $message, string $tail): void
    {
        $socket = new AbstractSocketJsonFramingTestProbe();
        $buffer = $input;

        $this->assertSame($message, $socket->extract($buffer));
        $this->assertSame($tail, $buffer);
        $this->assertFalse($socket->markedForClose);
    }

    /**
     * @return iterable<string, array{string, ?string, string}> Independent wire fixtures and expected frames
     */
    public static function framingCases(): iterable
    {
        yield 'empty' => ['', null, ''];
        yield 'ordinary-span' => ['ordinary bytes', null, 'ordinary bytes'];
        yield 'empty-object' => ["{}\nnext", '{}', 'next'];
        yield 'empty-array' => ["[]\n", '[]', ''];
        yield 'prefix' => [" \t\r\n{}\nnext", '{}', 'next'];
        $nested = '{"a":[{},[1,{"b":[]}]],"c":true}';
        yield 'nested-arrays-and-objects' => [$nested . "\n[]\n", $nested, "[]\n"];
        $strings = <<<'JSON'
{"brackets":"{}[]","quote":"a\"b","slash":"a\/b","backslash":"a\\b","newline":"\n","tab":"\t","unicode":"\u0001"}
JSON;
        yield 'structural-bytes-and-escaped-ordinary-bytes' => [$strings . "\ntail", $strings, 'tail'];
        $backslashes = <<<'JSON'
["\\","\\\\","\"","\\\"","\\\\\""]
JSON;
        yield 'odd-and-even-backslashes' => [$backslashes . "\n", $backslashes, ''];
        yield 'raw-newline-in-string-is-not-validation' => ["[\"a\nb\"]\n", "[\"a\nb\"]", ''];
        yield 'mismatched-bracket-is-not-validation' => ["[}\n", '[}', ''];
        yield 'escaped-ordinary-byte-outside-string' => ["\\x{}\ntail", '{}', 'tail'];
        yield 'escaped-opening-bracket-outside-string' => ["\\{}\n", null, "\\{}\n"];
        foreach (['{}', '{} ', "{}\r\n", "{}{}\n", '{"a":[]', '["unterminated', '["escape' . '\\'] as $input) {
            yield 'incomplete-' . bin2hex($input) => [$input, null, $input];
        }
    }

    /** Every two-part split waits for the delimiter and leaves the exact following bytes. */
    public function testEverySplitThroughEscapingWaitsForTheRestOfTheFrame(): void
    {
        $message = <<<'JSON'
{"a":["\n","\t","\u0001","\\","\\\"","{}[]","\/"],"b":{"c":1}}
JSON;
        $wire = $message . "\n";
        for ($split = 0; $split < strlen($wire); $split++) {
            $socket = new AbstractSocketJsonFramingTestProbe();
            $buffer = substr($wire, 0, $split);
            $this->assertNull($socket->extract($buffer), 'Split ' . $split);
            $this->assertSame(substr($wire, 0, $split), $buffer);
            $buffer .= substr($wire, $split) . 'next';
            $this->assertSame($message, $socket->extract($buffer));
            $this->assertSame('next', $buffer);
            $this->assertFalse($socket->markedForClose);
        }
    }

    /** Messages are consumed in order; repeated reads leave an incomplete tail untouched. */
    public function testSeveralMessagesLeaveAnIncompleteTail(): void
    {
        $socket = new AbstractSocketJsonFramingTestProbe();
        $buffer = "{}\n[1,{\"a\":[]}]\n{\"tail\":\"\\";

        $this->assertSame('{}', $socket->extract($buffer));
        $this->assertSame('[1,{"a":[]}]', $socket->extract($buffer));
        $this->assertSame('{"tail":"\\', $buffer);
        $this->assertNull($socket->extract($buffer));
        $this->assertNull($socket->extract($buffer));
        $this->assertSame('{"tail":"\\', $buffer);
    }

    /**
     * @param string $open Opening structural byte
     * @param string $close Closing structural byte
     */
    #[DataProvider('bracketKinds')]
    public function testDepthLimitAcceptsItsBoundaryAndIgnoresBracketsInStrings(string $open, string $close): void
    {
        $socket = new AbstractSocketJsonFramingTestProbe();
        $message = str_repeat($open, SocketConstants::MAX_JSON_DEPTH)
            . '"' . str_repeat('{}[]', SocketConstants::MAX_JSON_DEPTH) . '"'
            . str_repeat($close, SocketConstants::MAX_JSON_DEPTH);
        $buffer = $message . "\ntail";

        $this->assertSame($message, $socket->extract($buffer));
        $this->assertSame('tail', $buffer);
        $this->assertFalse($socket->markedForClose);
    }

    /**
     * @param string $open Opening structural byte
     * @param string $close Closing structural byte
     */
    #[DataProvider('bracketKinds')]
    public function testDepthOverflowMarksForCloseWithoutChangingTheBuffer(string $open, string $close): void
    {
        $socket = new AbstractSocketJsonFramingTestProbe();
        $input = str_repeat($open, SocketConstants::MAX_JSON_DEPTH + 1)
            . str_repeat($close, SocketConstants::MAX_JSON_DEPTH + 1) . "\n";
        $buffer = $input;

        try {
            $socket->extract($buffer);
            $this->fail('Nesting beyond the depth limit must raise');
        } catch (MessageTooLongException) {
            $this->assertTrue($socket->markedForClose);
            $this->assertSame($input, $buffer);
        }
    }

    /**
     * @return iterable<string, array{string, string}> Object and array delimiters
     */
    public static function bracketKinds(): iterable
    {
        yield 'objects' => ['{', '}'];
        yield 'arrays' => ['[', ']'];
    }
}

/** Exposes only the inherited framer, without opening a socket or changing its limits. */
final class AbstractSocketJsonFramingTestProbe extends AbstractSocket
{
    public bool $markedForClose = false;

    /**
     * @param string $buffer Bytes remaining after extraction
     * @return ?string Complete JSON bytes or null while incomplete
     * @throws SocketException When the inherited size or depth guard refuses the input
     */
    public function extract(string &$buffer): ?string
    {
        return $this->extractCompleteJsonMessage($buffer);
    }

    /** Record the inherited guard's close request. */
    public function markShouldClose(): void
    {
        $this->markedForClose = true;
    }
}

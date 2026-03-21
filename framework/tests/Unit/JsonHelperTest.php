<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JsonHelper (JSON extraction and safe decode helpers).
 */
final class JsonHelperTest extends TestCase
{
    /**
     * extractJsonObject returns null for an empty string.
     */
    public function testExtractJsonObjectReturnsNullForEmptyString(): void
    {
        $this->assertNull(JsonHelper::extractJsonObject(''));
    }

    /**
     * extractJsonObject returns null when the trimmed input is only whitespace.
     */
    public function testExtractJsonObjectReturnsNullForWhitespaceOnly(): void
    {
        $this->assertNull(JsonHelper::extractJsonObject(" \t\n "));
    }

    /**
     * extractJsonObject returns the trimmed JSON object when the whole string is a single object.
     */
    public function testExtractJsonObjectReturnsTrimmedObjectWhenWholeStringIsJson(): void
    {
        $json = '{"a":1,"b":"x"}';
        $this->assertSame($json, JsonHelper::extractJsonObject('  ' . $json . '  '));
    }

    /**
     * extractJsonObject finds the first {...} substring inside surrounding text.
     */
    public function testExtractJsonObjectExtractsFirstObjectFromSurroundingText(): void
    {
        $json = '{"k":"v"}';
        $text = 'prefix ' . $json . ' suffix';
        $this->assertSame($json, JsonHelper::extractJsonObject($text));
    }

    /**
     * extractJsonObject returns null when no JSON object braces are present.
     */
    public function testExtractJsonObjectReturnsNullWhenNoObjectFound(): void
    {
        $this->assertNull(JsonHelper::extractJsonObject('no braces here'));
    }

    /**
     * tryDecode returns null for an empty payload.
     */
    public function testTryDecodeReturnsNullForEmptyPayload(): void
    {
        $this->assertNull(JsonHelper::tryDecode(''));
    }

    /**
     * tryDecode returns null when the trimmed payload is only whitespace.
     */
    public function testTryDecodeReturnsNullForWhitespaceOnly(): void
    {
        $this->assertNull(JsonHelper::tryDecode("  \n  "));
    }

    /**
     * tryDecode returns null for malformed JSON.
     */
    public function testTryDecodeReturnsNullForInvalidJson(): void
    {
        $this->assertNull(JsonHelper::tryDecode('{not json'));
    }

    /**
     * tryDecode returns an associative array for a valid JSON object.
     */
    public function testTryDecodeReturnsAssociativeArrayForValidObject(): void
    {
        $expected = ['x' => 1, 'y' => 'z'];
        $this->assertSame($expected, JsonHelper::tryDecode('{"x":1,"y":"z"}'));
    }

    /**
     * tryDecode returns null for a JSON scalar (not an array at the top level).
     */
    public function testTryDecodeReturnsNullForJsonScalar(): void
    {
        $this->assertNull(JsonHelper::tryDecode('"only a string"'));
    }

    /**
     * tryDecode returns a list array for a JSON array payload.
     */
    public function testTryDecodeReturnsListForJsonArray(): void
    {
        $this->assertSame([1, 2, 3], JsonHelper::tryDecode('[1,2,3]'));
    }
}

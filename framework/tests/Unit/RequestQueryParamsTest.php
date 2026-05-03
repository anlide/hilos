<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\Exception\MissingRequestQueryParamException;
use Hilos\Core\Http\RequestQueryParams;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for typed request query params.
 */
final class RequestQueryParamsTest extends TestCase
{
    public function testFromQueryStringDecodesStringValues(): void
    {
        $params = RequestQueryParams::fromQueryString('name=Alice+Smith&city=New%20York&empty=');

        $this->assertSame('Alice Smith', $params->getString('name'));
        $this->assertSame('New York', $params->getString('city'));
        $this->assertSame('', $params->getString('empty'));
    }

    public function testRepeatedKeyKeepsLastValue(): void
    {
        $params = RequestQueryParams::fromQueryString('token=first&token=second');

        $this->assertSame('second', $params->getString('token'));
    }

    public function testPhpArraySyntaxStaysStringKey(): void
    {
        $params = RequestQueryParams::fromQueryString('token[]=abc');

        $this->assertNull($params->getString('token'));
        $this->assertSame('abc', $params->getString('token[]'));
    }

    public function testRequireStringThrowsForMissingValue(): void
    {
        $this->expectException(MissingRequestQueryParamException::class);
        $this->expectExceptionMessage('token is required');

        RequestQueryParams::empty()->requireString('token');
    }

    public function testRequireStringThrowsForEmptyValue(): void
    {
        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage('token cannot be empty');

        (new RequestQueryParams(['token' => '']))->requireString('token');
    }

    public function testRequireStringMatchingThrowsForInvalidFormat(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('token must be lowercase hex');

        (new RequestQueryParams(['token' => 'xyz']))->requireStringMatching(
            'token',
            '/\A[0-9a-f]{32}\z/',
            'token must be lowercase hex',
        );
    }

    public function testConstructorRejectsNonStringValue(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Request query params must be a string map');

        new RequestQueryParams(['token' => ['nested']]);
    }
}

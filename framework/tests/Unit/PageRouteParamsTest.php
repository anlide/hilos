<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageRouteParams;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageRouteParams accessors and missing-vs-invalid semantics.
 */
final class PageRouteParamsTest extends TestCase
{
    public function testHasReturnsFalseForMissingAndEmpty(): void
    {
        $params = new PageRouteParams(['a' => 'x', 'b' => '']);

        $this->assertTrue($params->has('a'));
        $this->assertFalse($params->has('b'));
        $this->assertFalse($params->has('missing'));
    }

    public function testGetStringReturnsNullForMissing(): void
    {
        $params = new PageRouteParams([]);

        $this->assertNull($params->getString('userId'));
    }

    public function testGetStringReturnsNullForEmpty(): void
    {
        $params = new PageRouteParams(['userId' => '']);

        $this->assertNull($params->getString('userId'));
    }

    public function testGetStringReturnsValue(): void
    {
        $params = new PageRouteParams(['userId' => '42']);

        $this->assertSame('42', $params->getString('userId'));
    }

    public function testRequireStringThrowsMissingForAbsent(): void
    {
        $params = new PageRouteParams([]);

        $this->expectException(MissingPageRouteParamException::class);
        $params->requireString('userId');
    }

    public function testRequireStringThrowsMissingForEmpty(): void
    {
        $params = new PageRouteParams(['userId' => '']);

        $this->expectException(MissingPageRouteParamException::class);
        $params->requireString('userId');
    }

    public function testGetIntReturnsNullForMissing(): void
    {
        $params = new PageRouteParams([]);

        $this->assertNull($params->getInt('userId'));
    }

    public function testGetIntAcceptsZero(): void
    {
        $params = new PageRouteParams(['userId' => '0']);

        $this->assertSame(0, $params->getInt('userId'));
        $this->assertSame(0, $params->requireInt('userId'));
    }

    public function testGetIntAcceptsNegative(): void
    {
        $params = new PageRouteParams(['offset' => '-5']);

        $this->assertSame(-5, $params->getInt('offset'));
        $this->assertSame(-5, $params->requireInt('offset'));
    }

    public function testGetIntThrowsInvalidOnGarbage(): void
    {
        $params = new PageRouteParams(['userId' => 'abc']);

        $this->expectException(InvalidPageRouteParamException::class);
        $params->getInt('userId');
    }

    public function testRequireIntThrowsInvalidOnGarbage(): void
    {
        $params = new PageRouteParams(['userId' => '4abc']);

        $this->expectException(InvalidPageRouteParamException::class);
        $params->requireInt('userId');
    }

    public function testRequireIntThrowsMissingForAbsent(): void
    {
        $params = new PageRouteParams([]);

        $this->expectException(MissingPageRouteParamException::class);
        $params->requireInt('userId');
    }

    public function testGetPositiveIntRejectsZero(): void
    {
        $params = new PageRouteParams(['userId' => '0']);

        $this->expectException(InvalidPageRouteParamException::class);
        $params->getPositiveInt('userId');
    }

    public function testGetPositiveIntRejectsNegative(): void
    {
        $params = new PageRouteParams(['userId' => '-1']);

        $this->expectException(InvalidPageRouteParamException::class);
        $params->getPositiveInt('userId');
    }

    public function testGetPositiveIntAcceptsPositive(): void
    {
        $params = new PageRouteParams(['userId' => '42']);

        $this->assertSame(42, $params->getPositiveInt('userId'));
        $this->assertSame(42, $params->requirePositiveInt('userId'));
    }

    public function testRequirePositiveIntThrowsMissingForAbsent(): void
    {
        $params = new PageRouteParams([]);

        $this->expectException(MissingPageRouteParamException::class);
        $params->requirePositiveInt('userId');
    }

    public function testGetEnumReturnsNullForMissing(): void
    {
        $params = new PageRouteParams([]);

        $this->assertNull($params->getEnum('mode', PageRouteParamsTestMode::class));
    }

    public function testGetEnumParsesValidCase(): void
    {
        $params = new PageRouteParams(['mode' => 'slow']);

        $this->assertSame(
            PageRouteParamsTestMode::Slow,
            $params->getEnum('mode', PageRouteParamsTestMode::class),
        );
    }

    public function testGetEnumThrowsInvalidForUnknownCase(): void
    {
        $params = new PageRouteParams(['mode' => 'weird']);

        $this->expectException(InvalidPageRouteParamException::class);
        $params->getEnum('mode', PageRouteParamsTestMode::class);
    }

    public function testRequireEnumThrowsMissingForAbsent(): void
    {
        $params = new PageRouteParams([]);

        $this->expectException(MissingPageRouteParamException::class);
        $params->requireEnum('mode', PageRouteParamsTestMode::class);
    }

    public function testRequireEnumParsesValidCase(): void
    {
        $params = new PageRouteParams(['mode' => 'fast']);

        $this->assertSame(
            PageRouteParamsTestMode::Fast,
            $params->requireEnum('mode', PageRouteParamsTestMode::class),
        );
    }

    public function testToArrayReturnsRawBackingMap(): void
    {
        $raw = ['userId' => '42', 'mode' => 'fast', 'empty' => ''];
        $params = new PageRouteParams($raw);

        $this->assertSame($raw, $params->toArray());
    }

    public function testMissingExceptionCarriesParamKeyAndHttpCode(): void
    {
        $params = new PageRouteParams([]);

        try {
            $params->requireString('userId');
            $this->fail('Expected MissingPageRouteParamException');
        } catch (MissingPageRouteParamException $e) {
            $this->assertInstanceOf(PageSubscriptionException::class, $e);
            $this->assertSame(400, $e->httpCode);
            $this->assertSame('missing_page_route_param', $e->errorCode);
            $this->assertSame('userId', $e->paramKey);
        }
    }

    public function testInvalidExceptionCarriesParamKeyAndHttpCode(): void
    {
        $params = new PageRouteParams(['userId' => 'abc']);

        try {
            $params->requireInt('userId');
            $this->fail('Expected InvalidPageRouteParamException');
        } catch (InvalidPageRouteParamException $e) {
            $this->assertInstanceOf(PageSubscriptionException::class, $e);
            $this->assertSame(400, $e->httpCode);
            $this->assertSame('invalid_page_route_param', $e->errorCode);
            $this->assertSame('userId', $e->paramKey);
        }
    }
}

/**
 * Backed enum fixture for PageRouteParamsTest::testGetEnum* tests.
 */
enum PageRouteParamsTestMode: string
{
    case Fast = 'fast';
    case Slow = 'slow';
}

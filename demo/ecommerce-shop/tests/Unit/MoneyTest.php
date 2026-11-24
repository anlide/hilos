<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Closure;
use Demo\EcommerceShop\Money\Money;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the money of the shop (HIL-2239).
 *
 * The counting and printing cases come from money-cases.json, the table the frontend module is
 * held to as well, so both sides answer every row the same way. Reading what a person wrote is
 * the backend's alone, and so are its cases here.
 */
final class MoneyTest extends TestCase
{
    /**
     * @param int $kopecks Amount
     * @param string $expected How the shop prints it
     */
    #[DataProvider('formatCases')]
    public function testFormatPrintsTheAmountTheWayTheShopShowsIt(int $kopecks, string $expected): void
    {
        self::assertSame($expected, Money::format($kopecks));
    }

    /**
     * @param int $price Price of one unit
     * @param int $quantity Units on the line
     * @param int $expected What the line costs
     */
    #[DataProvider('lineTotalCases')]
    public function testLineTotalIsThePriceTimesTheQuantity(int $price, int $quantity, int $expected): void
    {
        self::assertSame($expected, Money::lineTotal($price, $quantity));
    }

    /**
     * @param array<int, int> $amounts Amounts to add up
     * @param int $expected Their sum
     */
    #[DataProvider('sumCases')]
    public function testSumAddsTheAmountsUp(array $amounts, int $expected): void
    {
        self::assertSame($expected, Money::sum(...$amounts));
    }

    public function testExactLineTotalKeepsEveryDigitPastTheIntegerBoundary(): void
    {
        self::assertSame('0', Money::lineTotalExact(99_999_999, 0));
        self::assertSame('9099990809000091', Money::lineTotalExact(99_999_999, 90_999_909));
        self::assertSame('9223372036854775807', Money::lineTotalExact(PHP_INT_MAX, 1));
        self::assertSame('85070591730234615847396907784232501249', Money::lineTotalExact(PHP_INT_MAX, PHP_INT_MAX));
    }

    public function testExactSumKeepsEveryDigitPastTheIntegerBoundary(): void
    {
        self::assertSame('0', Money::sumExact());
        self::assertSame('0', Money::sumExact('0', '0'));
        self::assertSame('9999999999999999', Money::sumExact('4999999950000000', '5000000049999999'));
        self::assertSame('9318390588416093184', Money::sumExact(...array_fill(0, 1024, '9099990809000091')));
    }

    /**
     * @param int $items Amount of the items
     * @param int $percent Discount in percent
     * @param int $expected The discount, half a kopeck rounded up
     */
    #[DataProvider('discountOfCases')]
    public function testDiscountOfRoundsHalfAKopeckUp(int $items, int $percent, int $expected): void
    {
        self::assertSame($expected, Money::discountOf($items, $percent));
    }

    /**
     * @param int $items Amount of the items
     * @param int $discount Discount taken off
     * @param int $expected What is left to pay
     */
    #[DataProvider('totalAfterDiscountCases')]
    public function testTotalAfterDiscountTakesTheDiscountOffTheItems(int $items, int $discount, int $expected): void
    {
        self::assertSame($expected, Money::totalAfterDiscount($items, $discount));
    }

    /**
     * @param string $text Amount as a person wrote it
     * @param int $expected The amount in kopecks
     */
    #[DataProvider('readAmounts')]
    public function testParseReadsAnAmountInHryvnias(string $text, int $expected): void
    {
        self::assertSame($expected, Money::parse($text));
    }

    /**
     * @param string $text Text that is not an amount the shop reads
     * @param string $expected The words the person is answered with
     */
    #[DataProvider('refusedAmounts')]
    public function testParseRefusesWhatIsNotAnAmountInWords(string $text, string $expected): void
    {
        try {
            Money::parse($text);
        } catch (ValidationException $refusal) {
            self::assertSame($expected, $refusal->getMessage());

            return;
        }

        self::fail("'{$text}' was read as an amount");
    }

    /**
     * @param Closure(): mixed $call Operation called with a number no caller may pass
     */
    #[DataProvider('brokenPreconditions')]
    public function testABrokenPreconditionIsAMistakeOfTheProgram(Closure $call): void
    {
        $this->expectException(LogicException::class);

        $call();
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function formatCases(): iterable
    {
        foreach (self::cases('format') as [$kopecks, $expected]) {
            yield "{$kopecks}" => [$kopecks, $expected];
        }
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function lineTotalCases(): iterable
    {
        foreach (self::cases('lineTotal') as [$price, $quantity, $expected]) {
            yield "{$price} x {$quantity}" => [$price, $quantity, $expected];
        }
    }

    /**
     * @return iterable<string, array{array<int, int>, int}>
     */
    public static function sumCases(): iterable
    {
        foreach (self::cases('sum') as [$amounts, $expected]) {
            yield '[' . implode(', ', $amounts) . ']' => [$amounts, $expected];
        }
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function discountOfCases(): iterable
    {
        foreach (self::cases('discountOf') as [$items, $percent, $expected]) {
            yield "{$percent}% of {$items}" => [$items, $percent, $expected];
        }
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function totalAfterDiscountCases(): iterable
    {
        foreach (self::cases('totalAfterDiscount') as [$items, $discount, $expected]) {
            yield "{$items} less {$discount}" => [$items, $discount, $expected];
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function readAmounts(): array
    {
        return [
            'whole hryvnias' => ['410', 41000],
            'spaces at the ends and between the thousands' => [' 2 100 ', 210000],
            'kopecks after a point' => ['2 100.50', 210050],
            'one digit after a comma' => ['2 100,5', 210050],
            'a no-break space between the thousands' => ["2\u{00A0}100", 210000],
            'a narrow no-break space between the thousands' => ["2\u{202F}100", 210000],
            'a comma as the decimal mark' => ['99,90', 9990],
            'zero' => ['0', 0],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedAmounts(): array
    {
        $notAnAmount = 'Write the amount in hryvnias, like 410 or 2 100.50';
        $tooManyKopeckDigits = 'Write at most two digits after the point';

        return [
            'a comma before three digits' => ['2,100', $tooManyKopeckDigits],
            'a point before three digits' => ['1.234', $tooManyKopeckDigits],
            'letters' => ['abc', $notAnAmount],
            'a minus' => ['-5', $notAnAmount],
            'no hryvnias before the point' => ['.5', $notAnAmount],
            'nothing after the point' => ['5.', $notAnAmount],
            'the hryvnia sign' => ['₴410', $notAnAmount],
            'nothing' => ['', $notAnAmount],
            'a line break after the digits' => ["410\n", $notAnAmount],
            'sixteen digits' => ['1234567890123456', 'This amount is too large'],
        ];
    }

    /**
     * @return array<string, array{Closure(): mixed}>
     */
    public static function brokenPreconditions(): array
    {
        return [
            'a negative amount printed' => [static fn (): string => Money::format(-1)],
            'a negative price multiplied exactly' => [static fn (): string => Money::lineTotalExact(-1, 2)],
            'a negative quantity multiplied exactly' => [static fn (): string => Money::lineTotalExact(1, -2)],
            'an exact amount with trailing whitespace' => [static fn (): string => Money::sumExact("1\n")],
            'a discount over a hundred percent' => [static fn (): int => Money::discountOf(100, 101)],
            'a discount over the items' => [static fn (): int => Money::totalAfterDiscount(1, 2)],
        ];
    }

    /**
     * Reads the rows of one operation from the table both modules are held to.
     *
     * @param string $operation Key of the operation in the table
     * @return array<int, array<int, mixed>> Its rows: the arguments, then the expected result
     */
    private static function cases(string $operation): array
    {
        $table = json_decode((string) file_get_contents(__DIR__ . '/../money-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return $table[$operation];
    }
}

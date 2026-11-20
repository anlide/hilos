<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard: the shop counts and prints its money only in backend/Money (HIL-2239).
 *
 * Every PHP file of the shop's backend outside Money/ is read token by token, comments left
 * out. A name of money is a name with price, discount, kopeck or money in it, in any case, and
 * without percent: a share in percent is not money. The guard reports the hryvnia sign in a
 * string; number_format, NumberFormatter and a printf-family call whose format prints a
 * fraction; round, floor, ceil, intdiv, fdiv and fmod; the literal 100 beside a multiplication,
 * a division or a remainder; a name of money as an operand of arithmetic; and a name of money
 * printed raw, glued with a dot or put inside a string. Comparing it, assigning it, passing it
 * to a call and using it as an array key stay free.
 *
 * An operand is an access chain — a variable, ->name, ?->name, ::name, ::$name, an index, a
 * call — and it is money when any of its links is a name of money; what stands inside the
 * brackets of an index or a call is not a link of it, and neither is the class before ::.
 * Money under another name (total) is not seen: total also names the sum of a product's stock
 * across warehouses, and the guard would go red on that.
 */
final class MoneyGuardTest extends TestCase
{
    /**
     * Files of the backend allowed to count or print money anyway, by path from the backend,
     * each with its reason. Empty: nothing outside Money/ does it today.
     *
     * @var array<string, string>
     */
    private const array ALLOWED = [];

    private const string MODULE = 'Money/';
    private const string MESSAGE = 'Money of the shop is counted and printed only in backend/Money (README, "Money"): ';
    private const string MONEY_NAME = '/^(?!.*percent).*(price|discount|kopeck|money)/i';
    private const string SIGN = '₴';
    private const string FRACTION_FORMAT = '/%[-+ 0\'#]*\d*(\.\d+)?[fFeEgG]/';
    private const string NUMBER_FORMATTER = 'numberformatter';
    private const int HUNDRED = 100;

    /** @var array<int, string> */
    private const array NUMBER_PRINTERS = ['number_format'];

    /** @var array<int, string> */
    private const array FORMAT_PRINTERS = ['sprintf', 'printf', 'vsprintf', 'vprintf', 'fprintf', 'vfprintf'];

    /** @var array<int, string> */
    private const array ROUNDERS = ['round', 'floor', 'ceil', 'intdiv', 'fdiv', 'fmod'];

    /** @var array<int, int|string> */
    private const array HUNDRED_OPERATORS = ['*', '/', '%', T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL];

    /** @var array<int, int|string> */
    private const array ARITHMETIC = [
        '+', '-', '*', '/', '%', T_POW,
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL, T_POW_EQUAL, T_INC, T_DEC,
    ];

    /** @var array<int, int|string> */
    private const array GLUE = ['.', T_CONCAT_EQUAL];

    /** @var array<int, int|string> */
    private const array ACCESS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /** @var array<int, int|string> */
    private const array NOT_A_GLOBAL_CALL = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];

    /** @var array<int, int> */
    private const array NAMES = [T_VARIABLE, T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];

    /** @var array<int, int> */
    private const array CASTS = [T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_OBJECT_CAST];

    /** @var array<int, int|string> */
    private const array OPENING = ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE];

    /** @var array<int, int|string> */
    private const array CLOSING = [')', ']', '}'];

    /** @var array<int, int> */
    private const array UNREAD = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG];

    public function testShopBackendCountsAndPrintsMoneyOnlyInItsModule(): void
    {
        $judged = [];
        $findings = [];
        foreach ($this->backendFiles() as $file) {
            $path = $this->relativePath($file->getPathname());
            if (str_starts_with($path, self::MODULE) || array_key_exists($path, self::ALLOWED)) {
                continue;
            }
            $judged[] = $path;
            array_push($findings, ...self::findings($path, (string) file_get_contents($file->getPathname())));
        }

        self::assertContains('Database/Actions/ProductFields.php', $judged);
        self::assertNotContains('Money/Money.php', $judged);
        self::assertSame([], $findings);
    }

    /**
     * @param string $source Code that counts or prints money
     * @param string $expected The one finding it gives
     */
    #[DataProvider('violations')]
    public function testEachWayOfCountingOrPrintingMoneyIsFound(string $source, string $expected): void
    {
        self::assertSame([self::MESSAGE . 'Sample.php:1 — ' . $expected], self::findings('Sample.php', '<?php ' . $source));
    }

    /**
     * @param string $source Code that only compares, assigns, passes or keys money
     */
    #[DataProvider('cleanCode')]
    public function testWhatIsAllowedIsNotFound(string $source): void
    {
        self::assertSame([], self::findings('Sample.php', '<?php ' . $source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function violations(): array
    {
        return [
            'the sign' => ["'Price cannot be more than ₴999,999.99';", 'the sign ₴ in a string'],
            'number_format' => ['number_format($x, 2);', 'number_format() prints a number'],
            'sprintf with a fraction' => ["sprintf('%.2f', \$x);", 'sprintf() prints a number with a fraction'],
            'NumberFormatter' => ["new NumberFormatter('uk', 2);", 'NumberFormatter prints a number'],
            'round' => ['round($x);', 'round() rounds'],
            'a fully qualified intdiv' => ['\intdiv($a, $b);', 'intdiv() rounds'],
            'a hundred' => ['$a * 100;', 'the hundred beside *'],
            'a price multiplied' => ['$line->price * $line->quantity;', 'price counted with *'],
            'a price added to' => ['$total += $product->price;', 'price counted with +='],
            'a discount subtracted' => ['$items - $discount;', 'discount counted with -'],
            'a discount negated' => ['-$discount;', 'discount counted with -'],
            'a price glued' => ["'Costs ' . \$price;", 'price printed raw with .'],
            'a price inside a string' => ['"Costs {$product->price}";', 'price printed raw inside a string'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cleanCode(): array
    {
        return [
            'a comparison' => ['if ($value > self::PRICE_MAX) {}'],
            'an assignment' => ['$this->price = $price;'],
            'an array key' => ['[ProductTableRow::price => $product->price];'],
            'an argument' => ['Money::format($product->price);'],
            'other arithmetic' => ['$units + 1;'],
            'arithmetic on another property' => ['$stock->units * 2;'],
            'money inside the brackets of a call' => ['count($prices) * 2;'],
            'a percent' => ['$discountPercent > 0;'],
            'a percent in arithmetic' => ['$items * $discountPercent;'],
            'the sign in a comment' => ["// ₴ in a comment\n"],
            'the sign in a docblock' => ['/** ₴999,999.99 */'],
        ];
    }

    /**
     * Reads one file of the backend and reports every way it counts or prints money.
     *
     * @param string $path Path from the backend, for the message
     * @param string $source The code
     * @return array<int, string> One message per finding
     */
    private static function findings(string $path, string $source): array
    {
        $tokens = self::readTokens($source);
        $findings = [];
        $inString = false;
        foreach ($tokens as $index => [$kind, , $line]) {
            if ($kind === '"') {
                $inString = !$inString;
            } elseif ($kind === T_START_HEREDOC || $kind === T_END_HEREDOC) {
                $inString = $kind === T_START_HEREDOC;
            }
            $what = self::whatIsFound($tokens, $index, $inString);
            if ($what !== null) {
                $findings[] = self::MESSAGE . "{$path}:{$line} — {$what}";
            }
        }

        return $findings;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Token being judged
     * @param bool $inString Whether the token stands inside a string that prints what it holds
     * @return ?string What the token does with money, or null when nothing
     */
    private static function whatIsFound(array $tokens, int $index, bool $inString): ?string
    {
        [$kind, $text] = $tokens[$index];
        if (($kind === T_CONSTANT_ENCAPSED_STRING || $kind === T_ENCAPSED_AND_WHITESPACE) && str_contains($text, self::SIGN)) {
            return 'the sign ' . self::SIGN . ' in a string';
        }
        if ($inString) {
            return in_array($kind, [T_VARIABLE, T_STRING, T_STRING_VARNAME], true) && self::isMoney($text)
                ? self::bareName($text) . ' printed raw inside a string'
                : null;
        }
        if (in_array($kind, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            return self::whatTheNameDoes($tokens, $index);
        }
        if (($kind === T_LNUMBER || $kind === T_DNUMBER) && self::isHundred($kind, $text)) {
            $operator = self::operatorBeside($tokens, $index, self::HUNDRED_OPERATORS);

            return $operator === null ? null : "the hundred beside {$operator}";
        }
        if (in_array($kind, self::ARITHMETIC, true)) {
            $name = self::moneyOperand($tokens, $index);

            return $name === null ? null : "{$name} counted with {$text}";
        }
        if (in_array($kind, self::GLUE, true)) {
            $name = self::moneyOperand($tokens, $index);

            return $name === null ? null : "{$name} printed raw with {$text}";
        }

        return null;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Index of a name
     * @return ?string What the name does with a number, or null when it is no printer or rounder
     */
    private static function whatTheNameDoes(array $tokens, int $index): ?string
    {
        $before = $tokens[$index - 1][0] ?? null;
        if (in_array($before, self::NOT_A_GLOBAL_CALL, true)) {
            return null;
        }
        $segments = explode('\\', $tokens[$index][1]);
        if (strtolower(end($segments)) === self::NUMBER_FORMATTER) {
            return 'NumberFormatter prints a number';
        }
        $function = strtolower(ltrim($tokens[$index][1], '\\'));
        if (str_contains($function, '\\') || ($tokens[$index + 1][0] ?? null) !== '(') {
            return null;
        }
        if (in_array($function, self::NUMBER_PRINTERS, true)) {
            return "{$function}() prints a number";
        }
        if (in_array($function, self::ROUNDERS, true)) {
            return "{$function}() rounds";
        }
        if (in_array($function, self::FORMAT_PRINTERS, true) && self::printsAFraction($tokens, $index + 1)) {
            return "{$function}() prints a number with a fraction";
        }

        return null;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $open Index of the bracket opening the arguments
     * @return bool True when a string among the arguments prints a fraction
     */
    private static function printsAFraction(array $tokens, int $open): bool
    {
        $close = self::closingOf($tokens, $open);
        for ($cursor = $open + 1; $cursor < $close; $cursor++) {
            [$kind, $text] = $tokens[$cursor];
            if (($kind === T_CONSTANT_ENCAPSED_STRING || $kind === T_ENCAPSED_AND_WHITESPACE) && preg_match(self::FRACTION_FORMAT, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int|string $kind Kind of the number token
     * @param string $text The number as written
     * @return bool True when the number is a hundred
     */
    private static function isHundred(int|string $kind, string $text): bool
    {
        $digits = str_replace('_', '', $text);

        return $kind === T_LNUMBER ? intval($digits, 0) === self::HUNDRED : (float) $digits === (float) self::HUNDRED;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Index of the number
     * @param array<int, int|string> $operators Operators to look for
     * @return ?string The operator on either side of the number, or null when there is none
     */
    private static function operatorBeside(array $tokens, int $index, array $operators): ?string
    {
        foreach ([$index - 1, $index + 1] as $neighbor) {
            if (isset($tokens[$neighbor]) && in_array($tokens[$neighbor][0], $operators, true)) {
                return $tokens[$neighbor][1];
            }
        }

        return null;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Index of the operator
     * @return ?string The first name of money in the operand on its left or on its right, or null
     */
    private static function moneyOperand(array $tokens, int $index): ?string
    {
        foreach ([...self::linksBefore($tokens, $index), ...self::linksAfter($tokens, $index)] as $link) {
            if (self::isMoney($link)) {
                return self::bareName($link);
            }
        }

        return null;
    }

    /**
     * Walks the access chain that ends right before the operator.
     *
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Index of the operator
     * @return array<int, string> Names of its links
     */
    private static function linksBefore(array $tokens, int $index): array
    {
        $links = [];
        $cursor = $index - 1;
        while ($cursor >= 0) {
            $kind = $tokens[$cursor][0];
            if (in_array($kind, self::CLOSING, true)) {
                $cursor = self::openingOf($tokens, $cursor) - 1;
                continue;
            }
            if (!in_array($kind, self::NAMES, true) || self::isClassBeforeAccess($tokens, $cursor)) {
                break;
            }
            $links[] = $tokens[$cursor][1];
            if (!in_array($tokens[$cursor - 1][0] ?? null, self::ACCESS, true)) {
                break;
            }
            $cursor -= 2;
        }

        return $links;
    }

    /**
     * Walks the access chain that starts right after the operator.
     *
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Index of the operator
     * @return array<int, string> Names of its links
     */
    private static function linksAfter(array $tokens, int $index): array
    {
        $links = [];
        $cursor = $index + 1;
        while (in_array($tokens[$cursor][0] ?? null, self::CASTS, true)) {
            $cursor++;
        }
        $kind = $tokens[$cursor][0] ?? null;
        if ($kind === '(') {
            $cursor = self::closingOf($tokens, $cursor) + 1;
        } elseif (in_array($kind, self::NAMES, true)) {
            if (!self::isClassBeforeAccess($tokens, $cursor)) {
                $links[] = $tokens[$cursor][1];
            }
            $cursor++;
        } else {
            return [];
        }
        while (isset($tokens[$cursor])) {
            $kind = $tokens[$cursor][0];
            if ($kind === '(' || $kind === '[') {
                $cursor = self::closingOf($tokens, $cursor) + 1;
                continue;
            }
            if (!in_array($kind, self::ACCESS, true) || !in_array($tokens[$cursor + 1][0] ?? null, [T_STRING, T_VARIABLE], true)) {
                break;
            }
            $links[] = $tokens[$cursor + 1][1];
            $cursor += 2;
        }

        return $links;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $index Index of a name
     * @return bool True when the name is the class before :: rather than a link of the chain
     */
    private static function isClassBeforeAccess(array $tokens, int $index): bool
    {
        return $tokens[$index][0] !== T_VARIABLE && ($tokens[$index + 1][0] ?? null) === T_DOUBLE_COLON;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $open Index of an opening bracket
     * @return int Index of the bracket that closes it, or of the last token when none does
     */
    private static function closingOf(array $tokens, int $open): int
    {
        $depth = 0;
        for ($cursor = $open; isset($tokens[$cursor]); $cursor++) {
            $depth += self::depthChange($tokens[$cursor][0]);
            if ($depth === 0) {
                return $cursor;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * @param array<int, array{int|string, string, int}> $tokens Tokens worth reading
     * @param int $close Index of a closing bracket
     * @return int Index of the bracket that opens it, or zero when none does
     */
    private static function openingOf(array $tokens, int $close): int
    {
        $depth = 0;
        for ($cursor = $close; $cursor >= 0; $cursor--) {
            $depth -= self::depthChange($tokens[$cursor][0]);
            if ($depth === 0) {
                return $cursor;
            }
        }

        return 0;
    }

    /**
     * @param int|string $kind Kind of a token
     * @return int One for an opening bracket, minus one for a closing one, zero otherwise
     */
    private static function depthChange(int|string $kind): int
    {
        if (in_array($kind, self::OPENING, true)) {
            return 1;
        }

        return in_array($kind, self::CLOSING, true) ? -1 : 0;
    }

    /**
     * Tokenizes the code, leaving out whitespace, comments and the open tag, and gives every
     * one-character token its character as the kind and the line it stands on.
     *
     * @param string $source The code
     * @return array<int, array{int|string, string, int}> Kind, text and line of each token worth reading
     */
    private static function readTokens(string $source): array
    {
        $tokens = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            [$kind, $text] = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (!in_array($kind, self::UNREAD, true)) {
                $tokens[] = [$kind, $text, $line];
            }
            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    /**
     * @param string $name Name as written, with its $ or namespace when it has one
     * @return bool True when the name is a name of money
     */
    private static function isMoney(string $name): bool
    {
        return preg_match(self::MONEY_NAME, self::bareName($name)) === 1;
    }

    /**
     * @param string $name Name as written
     * @return string The name without its $ and namespace
     */
    private static function bareName(string $name): string
    {
        $segments = explode('\\', ltrim($name, '$'));

        return end($segments);
    }

    /**
     * Iterates the PHP files of the shop's backend.
     *
     * @return iterable<SplFileInfo>
     */
    private function backendFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/backend'));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    /**
     * Converts an absolute path to a path from the backend.
     */
    private function relativePath(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2) . '/backend') + 1));
    }
}

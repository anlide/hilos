<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use JsonException;
use stdClass;

/**
 * An opaque JSON object with lossless semantic comparison and no mutable data exposed.
 */
abstract readonly class AccountOperationJsonObject
{
    private const int DECIMAL_BASE = 10;
    private const string JSON_SCALAR_PATTERN = '/"(?:[^"\\\\]++|\\\\.)*"|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s';
    private const string JSON_NUMBER_PATTERN = '/^(?<sign>-?)(?<integer>[0-9]+)(?:\.(?<fraction>[0-9]+))?(?:[eE](?<exponent>[+-]?[0-9]+))?$/';

    /**
     * @param string $json Original checked JSON representation
     * @param stdClass $object Detached comparison tree with lossless tagged scalar values
     */
    final protected function __construct(private string $json, private stdClass $object)
    {
    }

    /**
     * @param string $json Complete account operation payload
     * @return static Checked immutable JSON-object boundary of the requested subtype
     * @throws InvalidJsonException When the payload is malformed JSON
     * @throws InvalidFormatException When the JSON root is not an object
     */
    public static function fromJson(string $json): static
    {
        if (!json_validate($json)) {
            throw new InvalidJsonException('The account operation payload must be valid JSON.');
        }
        try {
            // Tag strings and exact decimal numbers before decoding, so comparison never rounds a number into another value.
            $comparisonJson = preg_replace_callback(
                self::JSON_SCALAR_PATTERN,
                static fn(array $match): string => json_encode(
                    $match[0][0] === '"'
                        ? 's' . json_decode($match[0], flags: JSON_THROW_ON_ERROR)
                        : 'n' . self::normalizeJsonNumber($match[0]),
                    JSON_THROW_ON_ERROR,
                ),
                $json,
            );
            if ($comparisonJson === null) {
                throw new InvalidJsonException('The account operation payload could not be read.');
            }
            $object = json_decode($comparisonJson, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidJsonException('The account operation payload must be valid JSON.', previous: $exception);
        }
        if (!$object instanceof stdClass) {
            throw new InvalidFormatException('The account operation payload must be a JSON object.');
        }

        return new static($json, $object);
    }

    /** @return string Original checked JSON, including empty objects and nullable values */
    public function toJson(): string
    {
        return $this->json;
    }

    /**
     * @param self $other Payload to compare
     * @return bool Whether JSON values match, ignoring object-key order but preserving array order
     */
    public function equals(self $other): bool
    {
        return self::equalJsonValues($this->object, $other->object);
    }

    /**
     * @param mixed $left Decoded JSON value
     * @param mixed $right Decoded JSON value
     * @return bool Structural equality without scalar coercion across JSON types
     */
    private static function equalJsonValues(mixed $left, mixed $right): bool
    {
        if ($left instanceof stdClass && $right instanceof stdClass) {
            $leftFields = get_object_vars($left);
            $rightFields = get_object_vars($right);
            if (count($leftFields) !== count($rightFields)) {
                return false;
            }
            foreach ($leftFields as $key => $value) {
                if (!array_key_exists($key, $rightFields) || !self::equalJsonValues($value, $rightFields[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $index => $value) {
                if (!self::equalJsonValues($value, $right[$index])) {
                    return false;
                }
            }
            return true;
        }
        return $left === $right;
    }

    /**
     * @param string $number Valid JSON number token
     * @return string Exact signed significant digits and decimal exponent, with all zero spellings equal
     */
    private static function normalizeJsonNumber(string $number): string
    {
        preg_match(self::JSON_NUMBER_PATTERN, $number, $parts, PREG_UNMATCHED_AS_NULL);
        $digits = $parts['integer'];
        $fractionLength = 0;
        if ($parts['fraction'] !== null) {
            $digits .= $parts['fraction'];
            $fractionLength = strlen($parts['fraction']);
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '0';
        }
        $significantDigits = rtrim($digits, '0');
        $exponentShift = strlen($digits) - strlen($significantDigits) - $fractionLength;

        return $parts['sign'] . $significantDigits . 'e' . self::shiftDecimalExponent($parts['exponent'] ?? '0', $exponentShift);
    }

    /**
     * @param string $exponent Signed decimal exponent, possibly outside native integer range
     * @param int $shift Decimal places removed from the significand
     * @return string Normalized signed exponent after the exact shift
     */
    private static function shiftDecimalExponent(string $exponent, int $shift): string
    {
        $negative = str_starts_with($exponent, '-');
        $magnitude = ltrim($exponent, '+-0');
        if ($magnitude === '') {
            return (string)$shift;
        }
        $shiftNegative = $shift < 0;
        $shiftMagnitude = (string)abs($shift);
        $subtract = $negative !== $shiftNegative;
        if ($subtract && (strlen($magnitude) < strlen($shiftMagnitude)
            || (strlen($magnitude) === strlen($shiftMagnitude) && strcmp($magnitude, $shiftMagnitude) < 0))) {
            [$magnitude, $shiftMagnitude] = [$shiftMagnitude, $magnitude];
            $negative = $shiftNegative;
        }

        $exponentIndex = strlen($magnitude) - 1;
        $shiftIndex = strlen($shiftMagnitude) - 1;
        $carry = 0;
        $reversedDigits = '';
        while ($exponentIndex >= 0 || $shiftIndex >= 0 || $carry > 0) {
            $leftDigit = $exponentIndex >= 0 ? (int)$magnitude[$exponentIndex--] : 0;
            $rightDigit = $shiftIndex >= 0 ? (int)$shiftMagnitude[$shiftIndex--] : 0;
            $digit = $leftDigit + ($subtract ? -$rightDigit : $rightDigit) + $carry;
            $carry = 0;
            if ($digit < 0) {
                $digit += self::DECIMAL_BASE;
                $carry = -1;
            } elseif ($digit >= self::DECIMAL_BASE) {
                $digit -= self::DECIMAL_BASE;
                $carry = 1;
            }
            $reversedDigits .= (string)$digit;
        }
        $shiftedMagnitude = ltrim(strrev($reversedDigits), '0');
        if ($shiftedMagnitude === '') {
            return '0';
        }
        if ($negative) {
            return '-' . $shiftedMagnitude;
        }

        return $shiftedMagnitude;
    }
}

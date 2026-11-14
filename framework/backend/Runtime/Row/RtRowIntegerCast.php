<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\Snapshot\RtWork;

/**
 * Streaming PHP numeric-prefix cast for legacy connection metadata. A bounded
 * significand plus a sticky digit preserves double rounding before integer casting.
 */
final class RtRowIntegerCast
{
    private const int SIGNIFICAND_DIGITS = 1100;
    private const int DECIMAL_BASE = 10;
    private const int EXPONENT_BOUND = 10000;
    private const int CAST_SUFFIX_BYTES = 32;
    private const string START = 'start';
    private const string INTEGER = 'integer';
    private const string FRACTION = 'fraction';
    private const string EXPONENT = 'exponent';
    private const string EXPONENT_SIGN = 'exponentSign';
    private const string EXPONENT_DIGITS = 'exponentDigits';
    private const string END = 'end';

    private string $phase = self::START;
    private string $sign = '';
    private string $digits = '';
    private int $integerDigits = 0;
    private int $leadingZeros = 0;
    private int $digitCount = 0;
    private bool $fraction = false;
    private bool $sticky = false;
    private int $exponent = 0;
    private bool $negativeExponent = false;
    private bool $hasExponent = false;

    /**
     * @param RtRowReader $input Scalar, text or array used by the legacy cast
     * @return Generator<int, RtWork, int, int> Same integer as the legacy metadata reader
     * @throws InvalidFormatException When the segmented value is malformed
     */
    public static function read(RtRowReader $input): Generator
    {
        yield new RtWork();
        if ($input->kind() === RtRowKind::Scalar) {
            return (int)(yield from $input->scalar());
        }
        if ($input->kind() === RtRowKind::Map) {
            return (yield from $input->entries()->advance()) === null ? 0 : 1;
        }
        $parser = new self();
        $cursor = $input->text();
        while (($part = yield from $cursor->advance()) !== null) {
            $offset = 0;
            while ($offset < strlen($part)) {
                $bytes = yield new RtWork(strlen($part) - $offset);
                for ($end = $offset + $bytes; $offset < $end; ++$offset) {
                    $parser->accept($part[$offset]);
                }
            }
            if ($parser->phase === self::END) {
                break;
            }
        }
        $remaining = strlen($parser->digits) + self::CAST_SUFFIX_BYTES;
        while ($remaining > 0) {
            $remaining -= yield new RtWork($remaining);
        }
        $numeric = $parser->numericPrefix();
        $remaining = strlen($numeric);
        while ($remaining > 0) {
            $remaining -= yield new RtWork($remaining);
        }

        return (int)$numeric;
    }

    /**
     * @param string $character One byte of the numeric prefix
     */
    private function accept(string $character): void
    {
        if ($this->phase === self::END) {
            return;
        }
        if ($this->phase === self::START) {
            if (str_contains(" \t\r\n\v\f", $character)) {
                return;
            }
            $this->phase = self::INTEGER;
            if ($character === '+' || $character === '-') {
                $this->sign = $character;
                return;
            }
        }
        if ($character >= '0' && $character <= '9') {
            if (in_array($this->phase, [self::EXPONENT, self::EXPONENT_SIGN, self::EXPONENT_DIGITS], true)) {
                $this->hasExponent = true;
                $this->phase = self::EXPONENT_DIGITS;
                $digit = (int)$character;
                $this->exponent = $this->exponent > intdiv(PHP_INT_MAX - $digit, self::DECIMAL_BASE)
                    ? PHP_INT_MAX : $this->exponent * self::DECIMAL_BASE + $digit;
                return;
            }
            ++$this->digitCount;
            if ($this->phase === self::INTEGER) {
                ++$this->integerDigits;
            }
            if ($this->digits === '' && $character === '0') {
                ++$this->leadingZeros;
            } elseif (strlen($this->digits) < self::SIGNIFICAND_DIGITS) {
                $this->digits .= $character;
            } elseif ($character !== '0') {
                $this->sticky = true;
            }
            return;
        }
        if ($this->phase === self::INTEGER && $character === '.') {
            $this->fraction = true;
            $this->phase = self::FRACTION;
        } elseif (
            ($this->phase === self::INTEGER || $this->phase === self::FRACTION)
            && $this->digitCount > 0 && ($character === 'e' || $character === 'E')
        ) {
            $this->phase = self::EXPONENT;
        } elseif ($this->phase === self::EXPONENT && ($character === '-' || $character === '+')) {
            $this->negativeExponent = $character === '-';
            $this->phase = self::EXPONENT_SIGN;
        } else {
            $this->phase = self::END;
        }
    }

    /** @return string Bounded spelling with the same PHP integer-cast outcome */
    private function numericPrefix(): string
    {
        if ($this->digits === '') {
            return '0';
        }
        if (!$this->fraction && !$this->hasExponent) {
            if ($this->digitCount - $this->leadingZeros > strlen((string)PHP_INT_MAX)) {
                return $this->sign === '-' ? (string)PHP_INT_MIN : (string)PHP_INT_MAX;
            }

            return $this->sign . $this->digits;
        }
        $digits = $this->digits;
        if ($this->sticky) {
            $digits .= '1';
        }
        $scale = $this->integerDigits - $this->leadingZeros - strlen($digits);
        $exponent = $this->negativeExponent ? -$this->exponent : $this->exponent;
        if ($scale > 0 && $exponent > PHP_INT_MAX - $scale) {
            $scale = self::EXPONENT_BOUND;
        } elseif ($scale < 0 && $exponent < PHP_INT_MIN - $scale) {
            $scale = -self::EXPONENT_BOUND;
        } else {
            $scale += $exponent;
        }

        return $this->sign . $digits . 'e' . max(-self::EXPONENT_BOUND, min(self::EXPONENT_BOUND, $scale));
    }
}

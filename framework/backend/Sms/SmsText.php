<?php

declare(strict_types=1);

namespace Hilos\Sms;

/**
 * SmsText - segment sizing, single-segment truncation, and number masking for SMS (HIL-285).
 *
 * The text policy shared by the SMS channel agent and its tests. Two concerns:
 *
 *  - segment budget: a message using only GSM-7 characters fits {@see $gsmLimit} (one
 *    GSM segment, default 160) per segment; a message with any non-GSM character (e.g.
 *    Cyrillic) is sent as UCS-2 and fits only {@see UCS2_SEGMENT} (70). {@see truncate()}
 *    clamps an over-long body to one segment with a trailing ellipsis and reports the clamp
 *    so the agent can log it (never the body).
 *  - number masking: {@see maskNumber()} keeps only the last {@see VISIBLE_DIGITS} digits,
 *    so the delivery/failure log carries an auditable-but-not-identifying number - SMS logs
 *    never carry the full number or the message text (Auth codes travel this way).
 *
 * GSM detection is conservative: any character outside 7-bit ASCII flips the message to the
 * UCS-2 budget. This never over-fills a segment (a few GSM-7 accented letters are treated as
 * UCS-2, costing capacity but never truncating too little).
 */
final class SmsText
{
    /** Characters one UCS-2 (non-GSM) SMS segment holds. */
    public const int UCS2_SEGMENT = 70;

    /** Trailing marker appended when a body is clamped (kept ASCII so it never flips the alphabet). */
    public const string ELLIPSIS = '...';

    /** Trailing digits left visible by {@see maskNumber()}. */
    public const int VISIBLE_DIGITS = 4;

    /**
     * Clamps a body to a single segment, choosing the budget by alphabet.
     *
     * @param string $text Rendered message body
     * @param int $gsmLimit Segment budget for a GSM-7 message (the channel's max_length)
     * @return SmsTruncation Clamped body and whether a clamp happened
     */
    public static function truncate(string $text, int $gsmLimit): SmsTruncation
    {
        $limit = max(1, self::segmentLimit($text, $gsmLimit));
        if (mb_strlen($text) <= $limit) {
            return new SmsTruncation($text, false);
        }

        $keep = max(0, $limit - mb_strlen(self::ELLIPSIS));

        return new SmsTruncation(mb_substr($text, 0, $keep) . self::ELLIPSIS, true);
    }

    /**
     * Resolves the single-segment character budget for a body by its alphabet.
     *
     * @param string $text Rendered message body
     * @param int $gsmLimit Segment budget for a GSM-7 message
     * @return int UCS-2 budget when the body has any non-ASCII character, else the GSM budget
     */
    public static function segmentLimit(string $text, int $gsmLimit): int
    {
        return preg_match('/[^\x00-\x7F]/', $text) === 1 ? self::UCS2_SEGMENT : max(1, $gsmLimit);
    }

    /**
     * Masks a number for logging, keeping only its last {@see VISIBLE_DIGITS} characters.
     *
     * @param string $number Recipient number
     * @return string Number with everything but the trailing digits replaced by asterisks
     */
    public static function maskNumber(string $number): string
    {
        $length = mb_strlen($number);
        if ($length <= self::VISIBLE_DIGITS) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - self::VISIBLE_DIGITS) . mb_substr($number, -self::VISIBLE_DIGITS);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Auth;

use Hilos\Auth\Detection\IdentifierDetection;
use Hilos\Auth\Detection\IdentifierDetector;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Sms\SmsText;

/**
 * IdentifierMask - shortens an identifier to what a log may carry (HIL-607).
 *
 * An operator answering "did this person's click arrive" needs to tell one line
 * from the next; a log that carries whole addresses turns every rotation of it
 * into a mailing list. The middle ground the project already took for numbers
 * ({@see SmsText::maskNumber()}, used by the SMS channel agent) is generalized
 * here to the identifier as a whole, so the auth logs mask the same way whichever
 * kind was typed.
 *
 * The domain is kept whole on purpose: it names the deployment side of a failure
 * (a whole corporate domain bouncing, a typo'd public one) and is not personal.
 * The local part gives up everything but its first and last characters, and the
 * run between them is a FIXED width rather than one asterisk per hidden character
 * — a run that tracked the length would report how long the address is, which is
 * one of the two things needed to guess it.
 */
final class IdentifierMask
{
    /** Characters of the local part kept at its start, so two addresses can be told apart. */
    public const int VISIBLE_HEAD = 1;

    /** Characters of the local part kept at its end, the half a person recognizes their own address by. */
    public const int VISIBLE_TAIL = 4;

    /** Width of the asterisk run standing in for the hidden middle, whatever its real length. */
    private const int MASK_WIDTH = 4;

    /**
     * Masks an identifier for logging: `flowuser218@example.test` becomes
     * `f****r218@example.test`, a number delegates to {@see SmsText::maskNumber()}.
     *
     * Never throws and never returns the input unchanged: an identifier this cannot
     * classify is replaced by the bare run, because a string that is neither an
     * address nor a number is one nobody has vouched for, and a log is the wrong
     * place to find out what it was.
     *
     * @param string $identifier Identifier as it was submitted or normalized
     * @return string What may be written to a log in its place
     */
    public static function mask(string $identifier): string
    {
        try {
            $kind = IdentifierDetector::kindOf($identifier);
        } catch (InvalidFormatException) {
            return self::maskRun();
        }

        if ($kind === IdentifierDetection::KIND_PHONE) {
            return SmsText::maskNumber($identifier);
        }

        return self::maskAddress($identifier);
    }

    /**
     * Masks the local part of an address, keeping its head, its tail, and the domain.
     *
     * A local part too short to give up a middle keeps nothing at all: showing a
     * head and a tail that together spell the whole thing would mask in name only.
     *
     * @param string $address Address already known to be one
     * @return string The address with its local part shortened
     */
    private static function maskAddress(string $address): string
    {
        $at = mb_strrpos($address, '@');
        if ($at === false) {
            return self::maskRun();
        }

        $local = mb_substr($address, 0, $at);
        $domain = mb_substr($address, $at);
        if (mb_strlen($local) <= self::VISIBLE_HEAD + self::VISIBLE_TAIL) {
            return self::maskRun() . $domain;
        }

        return mb_substr($local, 0, self::VISIBLE_HEAD)
            . self::maskRun()
            . mb_substr($local, -self::VISIBLE_TAIL)
            . $domain;
    }

    /**
     * @return string The fixed-width asterisk run standing in for a hidden part
     */
    private static function maskRun(): string
    {
        return str_repeat('*', self::MASK_WIDTH);
    }
}

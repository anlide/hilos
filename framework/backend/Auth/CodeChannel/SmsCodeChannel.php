<?php

declare(strict_types=1);

namespace Hilos\Auth\CodeChannel;

use Hilos\Auth\PhoneNumber;
use Hilos\Auth\Verification\SmsVerificationDeliverer;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\Exception\EnvException;

/**
 * SmsCodeChannel - the SMS code channel (HIL-492).
 *
 * The channel every project has, and the reason the registry has a handoff door at
 * all: SMS delivery already exists as a subsystem with its own sharded pool, retries
 * and segment budget, so this channel sends nothing itself - it names SMS as a
 * choice, and passes the code to {@see SmsVerificationDeliverer}, which is the same
 * path the flow used before channels existed. A project whose registry holds only
 * this channel therefore behaves exactly as it did: one button, no icon row.
 *
 * It is the primary channel: SMS reaches a number with no prior relationship, which
 * is precisely what a stranger signing in has. A messenger cannot claim that, so
 * promoting one over SMS would show most people a default that cannot reach them.
 *
 * Reachability needs no network. The SMS gateway takes any well-formed E.164 number
 * and has no way to say in advance whether a given one is live, so the only honest
 * pre-check is the shape of the number - which is why {@see probeRequest()} stays
 * null and {@see reaches()} answers instead.
 */
final class SmsCodeChannel extends CodeChannel
{
    /** Registry key and stored channel value. */
    public const string NAME = 'sms';

    /**
     * @return string The `sms` channel name
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return string Upper-cased label: SMS is an initialism, not a capitalized word
     */
    public function label(): string
    {
        return 'SMS';
    }

    /**
     * @return bool True: SMS is the default channel a phone code goes over
     */
    public function isPrimary(): bool
    {
        return true;
    }

    /**
     * Delivers codes of the SMS-delivered types, and nothing else.
     *
     * The same set {@see SmsVerificationDeliverer} renders a template for, asked
     * ahead of the mint instead of discovering it as a silent no-op afterwards.
     *
     * @param string $type Verification type (see VerificationType)
     * @return bool True for the SMS verification types
     */
    public function supportsType(string $type): bool
    {
        return VerificationType::isSms($type);
    }

    /**
     * Whether the identifier is a number an SMS can be addressed to.
     *
     * The gateway accepts any well-formed E.164 number and cannot be asked whether a
     * particular one is live, so this is the whole of what a pre-check can honestly
     * decide.
     *
     * @param string $identifier Normalized identifier the code would go to
     * @return bool True when the identifier is a well-formed phone number
     */
    public function reaches(string $identifier): bool
    {
        return PhoneNumber::normalize($identifier) !== null;
    }

    /**
     * Hands the code to the SMS subsystem, which owns the transport.
     *
     * @param string $identifier Normalized E.164 number the code goes to
     * @param string $type Verification type the code was minted for (see VerificationType)
     * @param string $code Plaintext code to deliver
     * @throws EnvException When the SMS worker count is unreadable while sharding the number
     * @throws ValidationException When the code was issued for a blank number
     * @throws InvalidArgumentException When the SMS send signal cannot be named or queued
     */
    public function handoff(string $identifier, string $type, string $code): void
    {
        new SmsVerificationDeliverer()->deliver($identifier, $type, $code);
    }
}

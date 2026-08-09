<?php

declare(strict_types=1);

namespace Hilos\Mail\Smtp;

/**
 * Reply codes of the SMTP conversation (RFC 5321), named where the dialog reads them.
 *
 * Not an enum: the set is open. {@see SmtpReply::$code} carries whatever three digits
 * the server sent, and {@see SmtpDialog} classifies the whole 5xx range rather than a
 * listed member — neither reaches this class as a case.
 */
final class SmtpReplyCode
{
    /** @var int Service ready — the greeting, and the go-ahead after STARTTLS */
    public const int SERVICE_READY = 220;

    /** @var int Authentication succeeded */
    public const int AUTH_SUCCEEDED = 235;

    /** @var int Requested action taken and completed */
    public const int ACTION_OK = 250;

    /** @var int User not local; the server will forward the message */
    public const int USER_NOT_LOCAL_FORWARDED = 251;

    /** @var int Server asks for the next part of the AUTH exchange */
    public const int AUTH_CHALLENGE = 334;

    /** @var int Server is ready to receive the message body */
    public const int START_MAIL_INPUT = 354;

    /** @var int Lowest code of the permanent-failure range: at or above it, no retry helps */
    public const int PERMANENT_FAILURE_MIN = 500;
}

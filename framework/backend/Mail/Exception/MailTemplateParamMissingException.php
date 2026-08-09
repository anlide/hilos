<?php

declare(strict_types=1);

namespace Hilos\Mail\Exception;

use Hilos\Mail\Template\MailTemplate;

/**
 * A mail template was rendered without a param it cannot do without (HIL-197).
 *
 * Raised by a {@see MailTemplate} whose copy is built around a value the caller did not supply —
 * a verification code, a sign-in link, a notification title. Like an unknown template key, this
 * is a programming error at the call site, and refusing the render is the point: printing the
 * gap instead sends the recipient a subject-less email or a "your code is: " with nothing after
 * the colon, which reads as a working message and cannot be told apart from a delivery bug.
 */
final class MailTemplateParamMissingException extends MailException
{
}

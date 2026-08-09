<?php

declare(strict_types=1);

namespace Hilos\Sms\Exception;

use Hilos\Mail\Exception\MailTemplateParamMissingException;
use Hilos\Sms\Template\SmsTemplate;

/**
 * An SMS template was rendered without a param it cannot do without (HIL-285).
 *
 * The SMS counterpart of {@see MailTemplateParamMissingException}: raised
 * by an {@see SmsTemplate} whose single line is built around a value the caller did not supply.
 * Refusing costs nothing; sending "Your verification code is: " spends a paid segment on a
 * message the recipient can only read as a fault.
 */
final class SmsTemplateParamMissingException extends SmsException
{
}

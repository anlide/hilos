<?php

declare(strict_types=1);

namespace Hilos\Sms\Template;

use Hilos\Database\Verification\VerificationType;

/**
 * Keys and entry fields for an SMS template catalog (HIL-285).
 *
 * A catalog entry maps a template key to the {@see SmsTemplate} class that renders it. The
 * `auth.*` keys mirror the SMS-delivered {@see VerificationType} values so the verification
 * deliverer maps a type to its template by the same `'auth.' . $type` rule the mail catalog
 * uses. `notification.generic` renders a durable notification (HIL-196) from its
 * already-localized title/body; a project may add a per-type `notification.<type>` key by
 * overriding the catalog (array_replace).
 */
final class SmsTemplateCatalogConstants
{
    /** Catalog entry field: the SmsTemplate class that renders the key. */
    public const string TEMPLATE_CLASS = 'class';

    /** Template key: a one-time phone sign-in code. */
    public const string AUTH_SMS_LOGIN = 'auth.' . VerificationType::SMS_LOGIN;

    /** Template key: a one-time code confirming a phone added to a signed-in user. */
    public const string AUTH_SMS_ADD = 'auth.' . VerificationType::SMS_ADD;

    /** Template key: a durable notification delivered by SMS. */
    public const string NOTIFICATION_GENERIC = 'notification.generic';
}

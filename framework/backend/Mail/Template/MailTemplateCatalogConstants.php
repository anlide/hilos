<?php

declare(strict_types=1);

namespace Hilos\Mail\Template;

use Hilos\Database\Verification\VerificationType;

/**
 * Keys and entry fields for a mail template catalog.
 *
 * A catalog entry maps a template key to the {@see MailTemplate} class that renders
 * it. The `auth.*` keys mirror the email-delivered {@see VerificationType} values so
 * the verification deliverer (HIL-197 SLICE 7) maps a type to its template by the
 * same `'auth.' . $type` rule. `notification.generic` renders a durable notification
 * (HIL-196) from its already-localized title/body; a project may add a per-type
 * `notification.<type>` key by overriding the catalog (array_replace).
 */
final class MailTemplateCatalogConstants
{
    /** Catalog entry field: the MailTemplate class that renders the key. */
    public const string TEMPLATE_CLASS = 'class';

    /** Template key: confirm a freshly registered email identity. */
    public const string AUTH_REGISTER_CONFIRM = 'auth.' . VerificationType::REGISTER_CONFIRM;

    /** Template key: reset a password on an existing password identity. */
    public const string AUTH_PASSWORD_RESET = 'auth.' . VerificationType::PASSWORD_RESET;

    /** Template key: confirm a new email address for an existing user. */
    public const string AUTH_EMAIL_CHANGE = 'auth.' . VerificationType::EMAIL_CHANGE;

    /** Template key: a one-time passwordless sign-in link. */
    public const string AUTH_MAGIC_LINK = 'auth.' . VerificationType::MAGIC_LINK;

    /** Template key: confirm an email being added to a signed-in user. */
    public const string AUTH_EMAIL_ADD = 'auth.' . VerificationType::EMAIL_ADD;

    /** Template key: a durable notification delivered by email. */
    public const string NOTIFICATION_GENERIC = 'notification.generic';

    /**
     * Template key: a node frozen for maintenance with nothing happening behind it (HIL-482).
     *
     * Rendered and delivered with no database in the path at all - the alarm fires precisely when
     * the database may be half-written or unreadable, so its recipients come from the environment
     * and the message rides the raw-send intake rather than a persisted notification.
     */
    public const string PROTECTED_MODE_STUCK = 'protected-mode.stuck';

    /** Template key: the freeze an alert was raised about has been lifted (HIL-482). */
    public const string PROTECTED_MODE_CLEARED = 'protected-mode.cleared';
}

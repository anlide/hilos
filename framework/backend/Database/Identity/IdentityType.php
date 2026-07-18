<?php

declare(strict_types=1);

namespace Hilos\Database\Identity;

/**
 * IdentityType - fixed value set for the hilos_identity `type` column.
 *
 * Mirrors the SQL ENUM on the table. The closed set is fixed here at the
 * identity foundation (HIL-160) so per-method leaves (password / oauth /
 * magic-link / sms / passkey) write into the existing shape without touching
 * the migration. `identifier` meaning per type: password/magic_link = email
 * (lowercased), sms = phone (E.164), oauth = 'provider:subject',
 * passkey = credential id.
 */
final class IdentityType
{
    public const string PASSWORD = 'password';
    public const string OAUTH = 'oauth';
    public const string MAGIC_LINK = 'magic_link';
    public const string SMS = 'sms';
    public const string PASSKEY = 'passkey';
}

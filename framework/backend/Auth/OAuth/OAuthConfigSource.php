<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/**
 * OAuthConfigSource - where an OAuth provider field's effective value came from (HIL-286).
 *
 * The admin OAuth screens show this per field: what the administrator entered
 * ({@see DB} - the provider's row, or the settings row of the shared return address)
 * wins over the {@see ENV} value, and {@see DEFAULT} is the recipe's own value for a
 * field neither of them sets. For the client secret the value is never exposed;
 * {@see DEFAULT} then reads as "not set" unless the project's own recipe carries one.
 */
enum OAuthConfigSource: string
{
    /** The administrator's value, stored in the database, is in effect. */
    case DB = 'db';

    /** The value comes from an environment variable. */
    case ENV = 'env';

    /** The value is the recipe's own (no stored value, no env value). */
    case DEFAULT = 'default';
}

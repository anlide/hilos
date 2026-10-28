<?php

declare(strict_types=1);

namespace Hilos\Auth;

/** JSON boundary keys shared by the two test-user fixture commands. */
final class TestUserCommandConstants
{
    public const string FIELD_EMAIL = 'email';
    public const string FIELD_PASSWORD_HASH = 'passwordHash';
    public const string FIELD_THEME_PICK = 'themePick';
    public const string FIELD_PHONE = 'phone';
    public const string FIELD_NAME = 'name';
    public const string FIELD_USER_ID = 'userId';
    public const string FIELD_SESSION_TOKEN = 'sessionToken';
    public const string FIELD_SESSION_ID = 'sessionId';
}

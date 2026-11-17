<?php

declare(strict_types=1);

namespace Hilos\Auth\OAuth;

/** Field names of the channel-only provider cleanup contract. */
final class OAuthProviderTestCommandConstants
{
    public const string FIELD_PROVIDER_KEY = 'providerKey';
    public const string FIELD_SNAPSHOT = 'snapshot';
    public const string FIELD_ROW = 'row';
    public const string FIELD_ID = 'id';
    public const string FIELD_CURRENT_ID = 'currentId';
    public const string FIELD_CLIENT_ID = 'clientId';
    public const string FIELD_CLIENT_SECRET = 'clientSecret';
    public const string FIELD_SCOPE = 'scope';
    public const string FIELD_CREATED_AT = 'createdAt';
    public const string FIELD_UPDATED_AT = 'updatedAt';
    public const string FIELD_RESTORED = 'restored';
}

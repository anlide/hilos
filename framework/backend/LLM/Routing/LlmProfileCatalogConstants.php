<?php

declare(strict_types=1);

namespace Hilos\LLM\Routing;

/**
 * Field keys for an LLM profile catalog entry.
 *
 * A catalog entry maps a profile to the env variables that supply each field,
 * so profiles stay env-overridable per field (env defaults, later a settings
 * override). The local/external pair lets one profile resolve to either
 * provider depending on its provider env, choosing the matching url/model.
 */
final class LlmProfileCatalogConstants
{
    /** Env key holding the provider ('local' | 'external'). */
    public const string PROVIDER_ENV = 'providerEnv';

    /** Env key for the base URL used when the provider resolves to local. */
    public const string LOCAL_URL_ENV = 'localUrlEnv';

    /** Env key for the model used when the provider resolves to local. */
    public const string LOCAL_MODEL_ENV = 'localModelEnv';

    /** Env key for the base URL used when the provider resolves to external. */
    public const string EXTERNAL_URL_ENV = 'externalUrlEnv';

    /** Env key for the model used when the provider resolves to external. */
    public const string EXTERNAL_MODEL_ENV = 'externalModelEnv';

    /** Env key for the API key (external only). */
    public const string API_KEY_ENV = 'apiKeyEnv';

    /** Optional env key for the request timeout; null falls back to the default. */
    public const string TIMEOUT_ENV = 'timeoutEnv';

    /** Reserved literal placement hint; null today. */
    public const string PLACEMENT = 'placement';

    /** The framework built-in profile key. */
    public const string DEFAULT_PROFILE = 'default';
}

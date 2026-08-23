<?php

declare(strict_types=1);

namespace Hilos\Sms;

use Hilos\Constants\EnvConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\ChannelConfigResolver;
use Hilos\Sms\Delivery\SmsDeliveryChannel;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;

/**
 * SmsChannelConfig - the resolved gateway settings for one SMS send (HIL-285).
 *
 * The value {@see SmsDeliveryChannelAgent} reads to build a request and
 * classify the response, and the same effective config the admin channel page shows. It is
 * hybrid by design (HIL-200): the operational fields (endpoint, method, auth mode, field map,
 * success rule, from, timeout, max length) are layered settings-override -> env -> descriptor
 * default by {@see ChannelConfigResolver} through the channel's own {@see ChannelConfigField}
 * list, while the gateway secrets ({@see $apiKey}, {@see $apiPassword}) and the provider
 * selection live only in env. {@see provider} pins the driver (`generic` or `stub`); left empty
 * it auto-selects the stub whenever {@see endpointUrl} is empty, so a project with no gateway
 * sends nothing rather than failing. Tests build one directly.
 */
final class SmsChannelConfig
{
    /** Provider selection value forcing the config-driven HTTP gateway. */
    public const string PROVIDER_GENERIC = 'generic';

    /** Provider selection value forcing the dev/e2e stub. */
    public const string PROVIDER_STUB = 'stub';

    /** Auth mode: no gateway credentials applied. */
    public const string AUTH_MODE_NONE = 'none';

    /** Auth mode: the API key rides a query parameter. */
    public const string AUTH_MODE_QUERY = 'query';

    /** Auth mode: the API key rides an Authorization: Bearer header. */
    public const string AUTH_MODE_HEADER = 'header';

    /** Auth mode: HTTP Basic auth from the API key (user) and password. */
    public const string AUTH_MODE_BASIC = 'basic';

    /** Logical field-map key: the recipient number. */
    public const string MAP_KEY_TO = 'to';

    /** Logical field-map key: the message text. */
    public const string MAP_KEY_TEXT = 'text';

    /** Logical field-map key: the sender id. */
    public const string MAP_KEY_FROM = 'from';

    /**
     * @param ?string $provider Forced driver `generic`|`stub`, empty to auto-select, null when
     *                          there is no env accessor at all
     * @param string $endpointUrl Gateway endpoint URL, empty when no gateway is configured
     * @param string $httpMethod HTTP method for the gateway request (GET|POST)
     * @param string $authMode Gateway auth mode (none|query|header|basic)
     * @param array<string, string> $fieldMap Map of logical to/text/from keys to gateway param names
     * @param string $successRule Body substring that marks a 2xx success, or empty for HTTP-2xx-is-success
     * @param string $from Sender id applied to outgoing messages, or empty for none
     * @param int $timeoutMs Per-send timeout in milliseconds
     * @param int $maxLength Single-segment GSM-7 length budget
     * @param ?string $apiKey Gateway API key/token (env-only secret), null when there is no env
     *                        accessor at all
     * @param ?string $apiPassword Gateway API password for basic auth (env-only secret), null when
     *                             there is no env accessor at all
     */
    public function __construct(
        public readonly ?string $provider,
        public readonly string $endpointUrl,
        public readonly string $httpMethod,
        public readonly string $authMode,
        public readonly array $fieldMap,
        public readonly string $successRule,
        public readonly string $from,
        public readonly int $timeoutMs,
        public readonly int $maxLength,
        public readonly ?string $apiKey,
        public readonly ?string $apiPassword,
    ) {
    }

    /**
     * Resolves the effective SMS config for a channel: settings/env/default for operational
     * fields, env for the provider selection and secrets.
     *
     * @param SmsDeliveryChannel $channel Channel descriptor supplying the config fields
     * @return self Resolved gateway config
     * @throws DatabaseException When a persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When an env value is invalid for its type
     */
    public static function resolve(SmsDeliveryChannel $channel): self
    {
        $resolver = new ChannelConfigResolver();
        $fields = [];
        foreach ($channel->configFields() as $field) {
            $fields[$field->key] = $field;
        }

        $string = static fn(string $key): string => (string)$resolver->resolve($channel->name(), $fields[$key])->value;
        $int = static fn(string $key): int => (int)$resolver->resolve($channel->name(), $fields[$key])->value;

        return new self(
            provider: self::envString(EnvConstants::SMS_PROVIDER),
            endpointUrl: $string(SmsDeliveryChannel::FIELD_ENDPOINT_URL),
            httpMethod: strtoupper($string(SmsDeliveryChannel::FIELD_HTTP_METHOD)),
            authMode: $string(SmsDeliveryChannel::FIELD_AUTH_MODE),
            fieldMap: self::parseFieldMap($string(SmsDeliveryChannel::FIELD_FIELD_MAP)),
            successRule: $string(SmsDeliveryChannel::FIELD_SUCCESS_RULE),
            from: $string(SmsDeliveryChannel::FIELD_FROM),
            timeoutMs: $int(SmsDeliveryChannel::FIELD_TIMEOUT_MS),
            maxLength: max(1, $int(SmsDeliveryChannel::FIELD_MAX_LENGTH)),
            apiKey: self::envString(EnvConstants::SMS_API_KEY),
            apiPassword: self::envString(EnvConstants::SMS_API_PASSWORD),
        );
    }

    /**
     * Decides whether the config resolves to the dev/e2e stub rather than the HTTP gateway.
     *
     * @return bool True for an explicit `stub` selection or auto-selection with no endpoint
     */
    public function usesStub(): bool
    {
        if ($this->provider === self::PROVIDER_STUB) {
            return true;
        }

        if ($this->provider === self::PROVIDER_GENERIC) {
            return false;
        }

        return $this->endpointUrl === '';
    }

    /**
     * Resolves the gateway param name for a logical field-map key.
     *
     * @param string $logicalKey Logical key ({@see MAP_KEY_TO}, {@see MAP_KEY_TEXT}, {@see MAP_KEY_FROM})
     * @return string Mapped gateway param name, or the logical key when unmapped
     */
    public function gatewayParam(string $logicalKey): string
    {
        // external-boundary: the field map is JSON typed into settings and may name a key with an empty value
        $mapped = $this->fieldMap[$logicalKey] ?? '';

        return $mapped === '' ? $logicalKey : $mapped;
    }

    /**
     * Reads an env string, tolerating an unset env accessor.
     *
     * @param EnvConstants $key Env variable
     * @return ?string Env value, or null when the accessor is unset
     * @throws EnvException When the env value is invalid for its type
     */
    private static function envString(EnvConstants $key): ?string
    {
        return Hilos::$env?->string($key);
    }

    /**
     * Parses the field-map JSON string into a string-to-string map, falling back to identity.
     *
     * @param string $raw Field-map JSON string
     * @return array<string, string> Map of logical keys to gateway param names
     */
    private static function parseFieldMap(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded)) {
            return [
                self::MAP_KEY_TO => self::MAP_KEY_TO,
                self::MAP_KEY_TEXT => self::MAP_KEY_TEXT,
                self::MAP_KEY_FROM => self::MAP_KEY_FROM,
            ];
        }

        $map = [];
        foreach ($decoded as $logicalKey => $paramName) {
            if (is_string($logicalKey) && is_scalar($paramName)) {
                $map[$logicalKey] = (string)$paramName;
            }
        }

        return $map;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\AbstractDeliveryChannel;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\ChannelConfigValidators;
use Hilos\Sms\HilosSmsSender;

/**
 * SmsDeliveryChannel - the SMS delivery channel descriptor (HIL-285).
 *
 * The second concrete delivery channel (after email 197): it names the `sms` channel, points
 * the dispatcher at the SMS pool's deliver signal (HILOS_SMS_DELIVER), and resolves a
 * recipient's address from the verified `sms` identity - an unverified number is never an
 * address (SMS to an unproven number is both a leak and a cost). It is the SMS channel's
 * extension seam - a project subclasses it to source the address differently.
 *
 * The channel is pooled: its notification-delivery intake shards by the recipient's number
 * ({@see HilosSmsSender::shardKeyForNumber}), the same rule the raw-send intake uses, so
 * every message to one number - delivery or raw send - lands on the same pool instance and
 * its ordering (and any future per-number rate limit) stays local to that agent. Global
 * enablement gates only this delivery intake; Auth raw sends never pass through the dispatcher.
 *
 * Config is hybrid (HIL-200): the operational fields are settings-overridable on top of an
 * env default and so appear on the admin channel page automatically, while the gateway
 * secrets stay env-only.
 */
class SmsDeliveryChannel extends AbstractDeliveryChannel
{
    /** The `sms` channel name (registry key and delivery-row `channel` value). */
    public const string NAME = 'sms';

    /** Config field keys (fields-table row keys and settings sub-keys). */
    public const string FIELD_FROM = 'from';
    public const string FIELD_ENDPOINT_URL = 'endpoint_url';
    public const string FIELD_HTTP_METHOD = 'http_method';
    public const string FIELD_AUTH_MODE = 'auth_mode';
    public const string FIELD_FIELD_MAP = 'field_map';
    public const string FIELD_SUCCESS_RULE = 'success_rule';
    public const string FIELD_TIMEOUT_MS = 'timeout_ms';
    public const string FIELD_MAX_LENGTH = 'max_length';
    public const string FIELD_API_KEY = 'api_key';
    public const string FIELD_API_PASSWORD = 'api_password';

    /** Descriptor default HTTP method for a gateway request. */
    public const string DEFAULT_HTTP_METHOD = 'POST';

    /** Descriptor default auth mode (no gateway credentials applied). */
    public const string DEFAULT_AUTH_MODE = 'none';

    /** Descriptor default field map: gateway params equal the logical to/text/from keys. */
    public const string DEFAULT_FIELD_MAP = '{"to":"to","text":"text","from":"from"}';

    /** Descriptor default single-segment GSM-7 length budget. */
    public const int DEFAULT_MAX_LENGTH = 160;

    /**
     * @return string The `sms` channel name
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return string The `http` transport name shown in the admin channels hub (HIL-200)
     */
    public function driver(): string
    {
        return 'http';
    }

    /**
     * @return string The SMS pool's notification-delivery agent signal
     */
    public function deliverSignalName(): string
    {
        return HilosSignalConstants::HILOS_SMS_DELIVER;
    }

    /**
     * Resolves the recipient's verified SMS number from the framework identities.
     *
     * @param int $userId Recipient user id
     * @return ?string Verified E.164 number, or null when the recipient has none (or no DB context)
     * @throws DatabaseException When the identity lookup fails
     */
    public function resolveAddress(int $userId): ?string
    {
        return Hilos::$db?->identities->findVerifiedSmsByUser($userId);
    }

    /**
     * @return bool True: the SMS channel fans across an indexed agent pool
     */
    public function isPooled(): bool
    {
        return true;
    }

    /**
     * Shards by the recipient's number so both SMS intakes co-locate.
     *
     * @param int $userId Recipient user id
     * @param int $notificationId Notification id being delivered (unused; the number is the shard dimension)
     * @return ?int Positive pool shard key for the recipient number, or null when it no longer resolves
     * @throws DatabaseException When the identity lookup fails
     * @throws EnvException When SMS_WORKER_COUNT is unreadable
     */
    public function shardKeyFor(int $userId, int $notificationId): ?int
    {
        $address = $this->resolveAddress($userId);

        return $address === null ? null : HilosSmsSender::shardKeyForNumber($address);
    }

    /**
     * The SMS channel's config fields for the admin page: operational gateway config and env-only secrets.
     *
     * Only `from`, `endpoint_url`, and `timeout_ms` have an env backing; the remaining
     * operational fields (method, auth mode, field map, success rule, max length) carry a
     * descriptor default and are settings-overridable only. The API key and password are
     * secrets: env-only, never editable, never sent to the browser.
     *
     * @return list<ChannelConfigField> The SMS channel's config field descriptors
     */
    public function configFields(): array
    {
        return [
            new ChannelConfigField(self::FIELD_FROM, 'From (sender id)', SettingsCatalogConstants::TYPE_STRING, false, EnvConstants::SMS_FROM),
            new ChannelConfigField(self::FIELD_ENDPOINT_URL, 'Gateway endpoint URL', SettingsCatalogConstants::TYPE_STRING, false, EnvConstants::SMS_ENDPOINT_URL),
            new ChannelConfigField(self::FIELD_HTTP_METHOD, 'HTTP method', SettingsCatalogConstants::TYPE_STRING, false, null, self::DEFAULT_HTTP_METHOD, ChannelConfigValidators::httpMethod(...)),
            new ChannelConfigField(self::FIELD_AUTH_MODE, 'Auth mode', SettingsCatalogConstants::TYPE_STRING, false, null, self::DEFAULT_AUTH_MODE, ChannelConfigValidators::smsAuthMode(...)),
            new ChannelConfigField(self::FIELD_FIELD_MAP, 'Field map (JSON)', SettingsCatalogConstants::TYPE_STRING, false, null, self::DEFAULT_FIELD_MAP, ChannelConfigValidators::jsonObject(...)),
            new ChannelConfigField(self::FIELD_SUCCESS_RULE, 'Success rule', SettingsCatalogConstants::TYPE_STRING, false, null, ''),
            new ChannelConfigField(self::FIELD_TIMEOUT_MS, 'Send timeout (ms)', SettingsCatalogConstants::TYPE_INTEGER, false, EnvConstants::SMS_TIMEOUT_MS, 10000),
            new ChannelConfigField(self::FIELD_MAX_LENGTH, 'Max length (GSM-7 chars)', SettingsCatalogConstants::TYPE_INTEGER, false, null, self::DEFAULT_MAX_LENGTH),
            new ChannelConfigField(self::FIELD_API_KEY, 'Gateway API key', SettingsCatalogConstants::TYPE_STRING, true, EnvConstants::SMS_API_KEY),
            new ChannelConfigField(self::FIELD_API_PASSWORD, 'Gateway API password', SettingsCatalogConstants::TYPE_STRING, true, EnvConstants::SMS_API_PASSWORD),
        ];
    }
}

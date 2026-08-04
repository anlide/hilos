<?php

declare(strict_types=1);

namespace Hilos\Push;

use Hilos\Constants\EnvConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Notification\Delivery\ChannelConfigField;
use Hilos\Notification\Delivery\ChannelConfigResolver;
use Hilos\Push\Delivery\PushDeliveryChannel;
use Hilos\Push\Delivery\PushDeliveryChannelAgent;
use Hilos\Push\Exception\PushConfigException;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * PushChannelConfig - the resolved VAPID application-server keys for a push send (HIL-199).
 *
 * The value {@see PushDeliveryChannelAgent} reads to sign and encrypt a
 * web-push request, and the same effective config the admin channel page shows. It is hybrid by
 * design (HIL-200): the {@see $publicKey} is a settings-overridable, env-backed operational field
 * (it is served to the frontend so the browser subscribes, and the agent must sign with the same
 * key baked into every subscription), resolved by {@see ChannelConfigResolver} through the
 * channel's own {@see ChannelConfigField} list; the {@see $privateKey}
 * is an env-only secret read straight from env, never editable and never sent to the browser. The
 * {@see $subject} (a `mailto:` or URL contact) is also env-only. The key pair is generated once and
 * kept stable - regenerating it invalidates every existing subscription. Tests build one directly.
 */
final class PushChannelConfig
{
    /** Per-endpoint network timeout in milliseconds. */
    public const float DEFAULT_TIMEOUT_MS = 10000.0;

    /** Time-to-live in seconds a push service keeps an undelivered message (library default, 4 weeks). */
    public const int DEFAULT_TTL_SECONDS = 2419200;

    /**
     * @param string $publicKey VAPID application-server public key (base64url), or empty when unconfigured
     * @param string $privateKey VAPID application-server private key (base64url), or empty when unconfigured
     * @param string $subject VAPID contact subject (`mailto:` or URL), or empty when unconfigured
     * @param float $timeoutMs Per-endpoint network timeout in milliseconds
     * @param int $ttlSeconds Time-to-live in seconds for an undelivered message
     */
    public function __construct(
        public readonly string $publicKey,
        public readonly string $privateKey,
        public readonly string $subject,
        public readonly float $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * Resolves the effective push config: the public key through settings/env/default, the private
     * key and subject straight from env.
     *
     * @param PushDeliveryChannel $channel Channel descriptor supplying the config fields
     * @return self Resolved VAPID config
     * @throws DatabaseException When a persisted settings lookup fails
     * @throws SettingException When the settings catalog metadata or value is invalid
     * @throws EnvException When an env value is invalid for its type
     */
    public static function resolve(PushDeliveryChannel $channel): self
    {
        $fields = [];
        foreach ($channel->configFields() as $field) {
            $fields[$field->key] = $field;
        }

        $publicField = $fields[PushDeliveryChannel::FIELD_VAPID_PUBLIC];

        return new self(
            publicKey: (string)new ChannelConfigResolver()->resolve($channel->name(), $publicField)->value,
            privateKey: Hilos::$env?->string(EnvConstants::VAPID_PRIVATE) ?? '',
            subject: Hilos::$env?->string(EnvConstants::VAPID_SUBJECT) ?? '',
        );
    }

    /**
     * @return bool True when the VAPID key pair and subject are all present
     */
    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '' && $this->subject !== '';
    }

    /**
     * Validates and decodes the VAPID keys into the binary form the signer expects.
     *
     * @return array{subject: string, publicKey: string, privateKey: string} Decoded VAPID material
     * @throws PushConfigException When the config is unconfigured or a key is not a valid P-256 point
     */
    public function vapid(): array
    {
        if (!$this->isConfigured()) {
            throw new PushConfigException('push channel is not configured (VAPID key pair or subject missing)');
        }

        try {
            return VAPID::validate([
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ]);
        } catch (Throwable $e) {
            // VAPID::validate signals a bad key either as an ErrorException (point length) or an
            // InvalidArgumentException (malformed base64url); both are wrapped so the send settles
            // as a permanent failure instead of escaping the agent tick loop.
            throw new PushConfigException('invalid VAPID configuration: ' . $e->getMessage(), previous: $e);
        }
    }
}

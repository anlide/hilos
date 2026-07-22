<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

/**
 * OAuthPendingLogin - one in-flight OAuth login exchange, keyed by accept key (HIL-281).
 *
 * The internal RT op the OAuth login mechanism (mechanism B) hangs its non-blocking
 * work on. The callback action verifies the signed state synchronously (no I/O) and,
 * on success, records one of these keyed by the initiating connection's accept key,
 * then returns — its `action_success` means only "accepted, working", not "logged in".
 * The framework-owned OAuth async agent
 * ({@see \Hilos\Auth\OAuth\Agent\AbstractOAuthAgent}) observes the collection and
 * drives the token/userinfo round-trips across ticks off the master.
 *
 * It is a short-lived, best-effort record: {@see deadlineMs} bounds its life so a
 * dropped or failed-over exchange is expired rather than leaked. It is an INTERNAL
 * op, not a browser-gated table item — nothing here is surfaced to a page.
 */
final class OAuthPendingLogin extends RtState
{
    /** Runtime collection key registered by the project and used for RT sync. */
    public const string RT_COLLECTION = 'hilosOAuthPendingLogins';

    /** Flow mode: a plain sign-in exchange (the default). */
    public const string MODE_LOGIN = 'login';

    /** Flow mode: linking the resolved identity to {@see linkUserId} (HIL-401). */
    public const string MODE_LINK = 'link';

    public const string acceptKey = 'acceptKey';
    public const string sessionToken = 'sessionToken';
    public const string provider = 'provider';
    public const string code = 'code';
    public const string deadlineMs = 'deadlineMs';
    public const string mode = 'mode';
    public const string linkUserId = 'linkUserId';

    /** Accept key of the initiating connection; also this op's id and the failure target. */
    private(set) string $acceptKey = '';

    /** Session token to authenticate on success (the connection's live session). */
    public string $sessionToken = '';

    /** Provider key, e.g. 'oauth:github'. */
    public string $provider = '';

    /** Authorization code returned to the SPA callback, exchanged for a token. */
    public string $code = '';

    /** Absolute deadline in milliseconds after which the exchange is abandoned. */
    public float $deadlineMs = 0.0;

    /** Flow mode this op resolves under ({@see MODE_LOGIN} or {@see MODE_LINK}, HIL-401). */
    public string $mode = self::MODE_LOGIN;

    /** User the resolved identity is linked to under {@see MODE_LINK}; 0 for a login exchange. */
    public int $linkUserId = 0;

    /**
     * Builds a pending-login op for a verified callback.
     *
     * @param string $acceptKey Initiating connection accept key (op id + failure target)
     * @param string $sessionToken Session token to authenticate on success
     * @param string $provider Provider key, e.g. 'oauth:github'
     * @param string $code Authorization code to exchange
     * @param float $deadlineMs Absolute deadline in milliseconds
     * @param string $mode Flow mode ({@see MODE_LOGIN} default, {@see MODE_LINK})
     * @param int $linkUserId User the identity links to under {@see MODE_LINK}; 0 for login
     * @return static Fresh pending-login op
     */
    public static function create(
        string $acceptKey,
        string $sessionToken,
        string $provider,
        string $code,
        float $deadlineMs,
        string $mode = self::MODE_LOGIN,
        int $linkUserId = 0,
    ): static {
        $instance = new static();
        $instance->acceptKey = $acceptKey;
        $instance->sessionToken = $sessionToken;
        $instance->provider = $provider;
        $instance->code = $code;
        $instance->deadlineMs = $deadlineMs;
        $instance->mode = $mode;
        $instance->linkUserId = $linkUserId;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->acceptKey = (string)($row[self::acceptKey] ?? '');
        $instance->sessionToken = (string)($row[self::sessionToken] ?? '');
        $instance->provider = (string)($row[self::provider] ?? '');
        $instance->code = (string)($row[self::code] ?? '');
        $instance->deadlineMs = (float)($row[self::deadlineMs] ?? 0.0);
        $instance->mode = (string)($row[self::mode] ?? self::MODE_LOGIN);
        $instance->linkUserId = (int)($row[self::linkUserId] ?? 0);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @return string Runtime collection key for pending OAuth login ops
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the initiating connection accept key
     */
    public function getId(): string
    {
        return $this->acceptKey;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::acceptKey => $this->acceptKey,
            self::sessionToken => $this->sessionToken,
            self::provider => $this->provider,
            self::code => $this->code,
            self::deadlineMs => $this->deadlineMs,
            self::mode => $this->mode,
            self::linkUserId => $this->linkUserId,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/** An event attributed to the authenticated person who caused it. */
final readonly class AnalyticsPersonEvent
{
    public const string SIGN_IN = 'sign_in';
    public const string SIGN_OUT = 'sign_out';
    public const string TAKEOVER_START = 'takeover_start';
    public const string TAKEOVER_STOP = 'takeover_stop';
    public const string PAGE_OPEN = 'page_open';
    public const string PAGE_UPDATE = 'page_update';
    public const string ACTION = 'action';

    public const array KINDS = [
        self::SIGN_IN,
        self::SIGN_OUT,
        self::TAKEOVER_START,
        self::TAKEOVER_STOP,
        self::PAGE_OPEN,
        self::PAGE_UPDATE,
        self::ACTION,
    ];

    /**
     * @param ?string $sessionToken Browser session token, or null
     * @param int $userId Authenticated actor
     * @param ?int $subjectUserId Account under takeover, or null
     * @param ?int $sessionId Application session id, or null
     * @param string $eventKind One of the KINDS constants
     * @param ?string $action Action name for an action, or null
     * @param ?string $page Page name for navigation, or null
     * @param ?array<string, mixed> $params Page route parameters, or null
     * @param ?string $ip Client address, or null
     * @param int $ts Event moment in milliseconds
     */
    public function __construct(
        public ?string $sessionToken,
        public int $userId,
        public ?int $subjectUserId,
        public ?int $sessionId,
        public string $eventKind,
        public ?string $action,
        public ?string $page,
        public ?array $params,
        public ?string $ip,
        public int $ts,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Notification;

/**
 * NotificationDraft - the value object a caller hands to {@see HilosNotifier::emit()}.
 *
 * Describes one notification to persist and deliver: its recipient, machine type,
 * severity, and the title/body rendered in the default locale at emit time, plus
 * optional structured `data` a later i18n pass can re-render from. It is a plain
 * input VO — persistence, id assignment, and the live signal fan are the
 * notifier's job.
 */
final class NotificationDraft
{
    /**
     * @param int $userId Recipient user id
     * @param string $type Machine notification type (e.g. backup.completed)
     * @param string $title Rendered title (default locale at emit)
     * @param string $severity Severity level (see NotificationSeverity; defaults to info)
     * @param ?string $body Rendered body, or null
     * @param ?array<string, mixed> $data Structured context, or null
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $severity = NotificationSeverity::INFO,
        public readonly ?string $body = null,
        public readonly ?array $data = null,
    ) {
    }
}

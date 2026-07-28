<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

/**
 * NotificationPreferencesSectionData - the profile "Notifications" section payload (HIL-485).
 *
 * The computed projection delivered in the profile page subscription scope (page
 * data section), not a browser snapshot: it is a derived view over the channel
 * registry, the global channel switches (HIL-200), the recipient's addresses
 * (HIL-196), and their sparse opt-out rows. Carries one {@see NotificationChannelState}
 * per globally enabled channel and a `mandatoryNote` flag telling the frontend to
 * render the always-on line ("security messages always arrive") when the project's
 * type registry declares any mandatory type.
 */
final class NotificationPreferencesSectionData
{
    /** Payload key: the per-channel rows. */
    public const string channels = 'channels';

    /** Payload key: whether to render the mandatory-types always-on note. */
    public const string mandatoryNote = 'mandatoryNote';

    /**
     * @param list<NotificationChannelState> $channels Channel rows over globally enabled channels
     * @param bool $mandatoryNote Whether the project declares any mandatory notification type
     */
    public function __construct(
        public readonly array $channels,
        public readonly bool $mandatoryNote,
    ) {
    }

    /**
     * @return array<string, mixed> Section as a wire payload
     */
    public function toArray(): array
    {
        return [
            self::channels => array_map(
                static fn (NotificationChannelState $state): array => $state->toArray(),
                $this->channels,
            ),
            self::mandatoryNote => $this->mandatoryNote,
        ];
    }
}

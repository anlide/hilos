<?php

declare(strict_types=1);

namespace Hilos\Notification\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\Library\AbstractNotificationsLibraryAgent;
use Hilos\Notification\NotificationCommandConstants;
use Hilos\Notification\NotificationDraft;

/**
 * Any worker → notifications library: this notification, please (HIL-771).
 *
 * A {@see NotificationDraft} on the wire, and nothing more: the emit seam stopped writing the
 * row where it was called and now asks the owner of the notification set to write it
 * ({@see HilosNotifier::emit()} → {@see AbstractNotificationsLibraryAgent}). What travels is
 * therefore exactly what a caller already hands the facade, field for field.
 *
 * The keys are {@see NotificationCommandConstants}' rather than a second spelling of the same
 * six names: the test-only emit command has carried this payload over the command channel
 * since HIL-514, and one vocabulary for both entrances is what keeps them from drifting apart.
 *
 * Severity is required here while {@see NotificationDraft} defaults it, because by this point
 * the default has already been applied by the caller-side facade - a payload that left it out
 * would be a draft that lost a field in transit rather than one that never named it.
 */
final class NotificationEmitSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param int $userId Recipient user id
     * @param string $type Machine notification type (e.g. backup.completed)
     * @param string $severity Severity level (see NotificationSeverity)
     * @param string $title Rendered title (default locale at emit)
     * @param ?string $body Rendered body, or null when the notification is a title alone
     * @param ?array<string, mixed> $data Structured context a later i18n pass re-renders from, or null
     * @param ?list<string> $channels Channel narrowing: null delivers to every enabled channel
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?array $data = null,
        public readonly ?array $channels = null,
    ) {
    }

    /**
     * Wraps one draft for the trip to the library.
     *
     * @param NotificationDraft $draft Draft handed to the emit seam
     * @return self Payload carrying that draft whole
     */
    public static function fromDraft(NotificationDraft $draft): self
    {
        return new self(
            userId: $draft->userId,
            type: $draft->type,
            severity: $draft->severity,
            title: $draft->title,
            body: $draft->body,
            data: $draft->data,
            channels: $draft->channels,
        );
    }

    /**
     * Rebuilds the draft on the library side.
     *
     * @return NotificationDraft The draft the caller handed the facade
     */
    public function toDraft(): NotificationDraft
    {
        return new NotificationDraft(
            userId: $this->userId,
            type: $this->type,
            title: $this->title,
            severity: $this->severity,
            body: $this->body,
            data: $this->data,
            channels: $this->channels,
        );
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            NotificationCommandConstants::FIELD_USER_ID => $this->userId,
            NotificationCommandConstants::FIELD_TYPE => $this->type,
            NotificationCommandConstants::FIELD_SEVERITY => $this->severity,
            NotificationCommandConstants::FIELD_TITLE => $this->title,
            NotificationCommandConstants::FIELD_BODY => $this->body,
            NotificationCommandConstants::FIELD_DATA => $this->data,
            NotificationCommandConstants::FIELD_CHANNELS => $this->channels,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no recipient, type, severity or title
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId: self::requireInt($data, NotificationCommandConstants::FIELD_USER_ID),
            type: self::requireString($data, NotificationCommandConstants::FIELD_TYPE),
            severity: self::requireString($data, NotificationCommandConstants::FIELD_SEVERITY),
            title: self::requireString($data, NotificationCommandConstants::FIELD_TITLE),
            body: self::optionalString($data, NotificationCommandConstants::FIELD_BODY),
            data: self::optionalArray($data, NotificationCommandConstants::FIELD_DATA),
            channels: self::optionalChannels($data),
        );
    }

    /**
     * Reads the channel narrowing, keeping absence and "every channel" the same answer.
     *
     * An empty list is folded into null on purpose: the dispatcher reads null as "every enabled
     * channel" and a list as "only these", so a narrowing that survived the trip as `[]` would
     * silently deliver a notification nowhere.
     *
     * @param array<string, mixed> $data Source data
     * @return ?list<string> Named channels, or null when the draft narrowed nothing
     * @throws InvalidFormatException When the key is present and holds a non-array
     */
    private static function optionalChannels(array $data): ?array
    {
        $channels = array_values(array_filter(
            self::optionalArray($data, NotificationCommandConstants::FIELD_CHANNELS) ?? [],
            static fn(mixed $channel): bool => is_string($channel) && $channel !== '',
        ));

        return $channels === [] ? null : $channels;
    }
}

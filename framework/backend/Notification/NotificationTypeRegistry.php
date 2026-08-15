<?php

declare(strict_types=1);

namespace Hilos\Notification;

use Hilos\Backup\BackupNotificationType;
use Hilos\Hilos;

/**
 * NotificationTypeRegistry - the code-side catalog of notification types (HIL-485).
 *
 * Maps a machine notification type to its {@see NotificationTypeDescriptor}. The base
 * carries the types the framework itself emits; a project points
 * {@see Hilos::NOTIFICATION_TYPE_REGISTRY} at its own subclass and adds
 * types by overriding {@see types()} with `array_replace(parent::types(), [...])`.
 *
 * The dispatcher reads {@see isMandatory()} to decide whether a type bypasses
 * per-user channel preferences (HIL-485). An unregistered type is treated as
 * non-mandatory, so a project need only declare the types that must not be muted.
 */
abstract class NotificationTypeRegistry
{
    /**
     * The type descriptors keyed by machine notification type.
     *
     * A project overrides this and merges its own entries onto the parent's:
     *
     * ```php
     * protected static function types(): array
     * {
     *     return array_replace(parent::types(), [
     *         'security.alert' => new NotificationTypeDescriptor(mandatory: true),
     *     ]);
     * }
     * ```
     *
     * The framework's own entries are the restore outcomes (HIL-279), and they are mandatory:
     * the database an administrator works on was replaced under them, which is not a thing a
     * personal channel setting may turn off. A project that does not activate backups inherits
     * two types nothing ever emits, which costs it nothing.
     *
     * @return array<string, NotificationTypeDescriptor> Type descriptors keyed by type
     */
    protected static function types(): array
    {
        return [
            BackupNotificationType::RESTORE_SUCCEEDED => new NotificationTypeDescriptor(mandatory: true),
            BackupNotificationType::RESTORE_FAILED => new NotificationTypeDescriptor(mandatory: true),
        ];
    }

    /**
     * Returns one type descriptor by type, or null when it is not registered.
     *
     * @param string $type Machine notification type
     * @return ?NotificationTypeDescriptor Descriptor, or null when unregistered
     */
    public static function get(string $type): ?NotificationTypeDescriptor
    {
        return static::types()[$type] ?? null;
    }

    /**
     * Whether a type is mandatory (bypasses per-user channel preferences).
     *
     * @param string $type Machine notification type
     * @return bool True when the registered type is mandatory; false when non-mandatory or unregistered
     */
    public static function isMandatory(string $type): bool
    {
        return static::get($type)?->mandatory ?? false;
    }

    /**
     * Whether the project declares at least one mandatory type.
     *
     * The profile notification section (HIL-485) reads this to decide whether to
     * render its always-on note; when no mandatory type exists the note is omitted.
     *
     * @return bool True when any registered type is mandatory
     */
    public static function hasMandatory(): bool
    {
        foreach (static::types() as $descriptor) {
            if ($descriptor->mandatory) {
                return true;
            }
        }

        return false;
    }
}

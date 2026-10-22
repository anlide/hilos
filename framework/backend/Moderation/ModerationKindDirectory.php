<?php

declare(strict_types=1);

namespace Hilos\Moderation;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;

/**
 * The code-side directory of the kinds of check a project sends to the moderator (HIL-1623).
 *
 * The framework declares a person's display name and profile photo. A project points
 * {@see Hilos::MODERATION_KIND_DIRECTORY} at a subclass, keeps the parent's entries and appends
 * its own; the order is the order of the administration screen.
 *
 * The declaration is a list, not a map, so a key typed twice reaches the check instead of
 * silently replacing the first entry. A duplicate, a project key in the framework's `hilos_`
 * namespace, or a framework entry dropped or changed is refused, never dropped or replaced.
 * The photo kind leaves the published set when the project does not take profile photos.
 */
abstract class ModerationKindDirectory
{
    /** Key prefix reserved for the framework's own kinds. */
    private const string FRAMEWORK_KEY_PREFIX = 'hilos_';

    /**
     * A project overrides this and appends its own kinds after the parent's:
     *
     * ```php
     * protected static function kinds(): array
     * {
     *     return [...parent::kinds(), new ModerationKind(...)];
     * }
     * ```
     *
     * @return list<ModerationKind> Declared kinds in administration order
     * @throws InvalidArgumentException When a declared kind is malformed
     */
    protected static function kinds(): array
    {
        return [
            new ModerationKind(
                ModerationKindKey::PROFILE_NAME,
                'Profile name',
                ModerationInput::TEXT,
                ['insult', 'threat', 'hate_speech', 'sexual', 'spam', 'impersonation'],
                [
                    'Default policy: allow benign display names.',
                    'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
                    'If uncertain, return allow=true.',
                ],
                HilosAgentType::HILOS_USERS_LIBRARY,
            ),
            new ModerationKind(
                ModerationKindKey::PROFILE_PHOTO,
                'Profile photo',
                ModerationInput::IMAGE,
                ['nudity', 'violence', 'hate'],
                [
                    'Default policy: allow ordinary photos, drawings and logos.',
                    'Block only nudity or sexual content, graphic violence, and hate symbols.',
                    'If uncertain, return allow=true.',
                ],
                HilosAgentType::HILOS_USERS_LIBRARY,
            ),
        ];
    }

    /**
     * Returns the published kinds keyed by their own key, in administration order.
     *
     * The whole declaration is checked first, so a bad photo entry is refused even while the
     * project does not take profile photos; only then is the photo kind left out.
     *
     * @param bool $profilePhotoEnabled Whether the project takes profile photos
     * @return array<string, ModerationKind> Kinds keyed by kind key
     * @throws InvalidArgumentException When the declaration is not a list of kinds, repeats a key, uses the
     *     framework's key prefix for a project kind, or drops or changes a framework kind
     */
    final public static function all(bool $profilePhotoEnabled): array
    {
        $declared = static::kinds();
        if (!array_is_list($declared)) {
            throw new InvalidArgumentException(static::class . ' must declare its moderation kinds as a list');
        }
        $framework = self::frameworkKinds();
        $kinds = [];
        foreach ($declared as $position => $kind) {
            if (!$kind instanceof ModerationKind) {
                throw new InvalidArgumentException(
                    static::class . " declares a moderation kind at position {$position} that is " . get_debug_type($kind),
                );
            }
            if (isset($kinds[$kind->key])) {
                throw new InvalidArgumentException(static::class . " declares the moderation kind {$kind->key} twice");
            }
            if (isset($framework[$kind->key])) {
                if (!self::isSameKind($kind, $framework[$kind->key])) {
                    throw new InvalidArgumentException(static::class . " changes the framework moderation kind {$kind->key}");
                }
            } elseif (str_starts_with($kind->key, self::FRAMEWORK_KEY_PREFIX)) {
                throw new InvalidArgumentException(
                    static::class . " declares {$kind->key}, but moderation kind keys starting with "
                    . self::FRAMEWORK_KEY_PREFIX . ' are reserved for the framework',
                );
            }
            $kinds[$kind->key] = $kind;
        }
        foreach (array_keys($framework) as $key) {
            if (!isset($kinds[$key])) {
                throw new InvalidArgumentException(static::class . " drops the framework moderation kind {$key}");
            }
        }
        if (!$profilePhotoEnabled) {
            unset($kinds[ModerationKindKey::PROFILE_PHOTO]);
        }

        return $kinds;
    }

    /**
     * Whether a key belongs to one of the framework's own kinds.
     *
     * @param string $key Candidate kind key
     * @return bool Whether the base directory declares the kind
     * @throws InvalidArgumentException When a framework kind is malformed
     */
    final public static function isFramework(string $key): bool
    {
        return isset(self::frameworkKinds()[$key]);
    }

    /**
     * @return array<string, ModerationKind> The base directory's kinds keyed by kind key
     * @throws InvalidArgumentException When a framework kind is malformed
     */
    private static function frameworkKinds(): array
    {
        $kinds = [];
        foreach (self::kinds() as $kind) {
            $kinds[$kind->key] = $kind;
        }

        return $kinds;
    }

    /**
     * Compares two kinds field by field and strictly, which `==` on objects would not.
     *
     * @param ModerationKind $declared Kind a directory declares
     * @param ModerationKind $framework Framework kind under the same key
     * @return bool Whether the declared kind is the framework kind unchanged
     */
    private static function isSameKind(ModerationKind $declared, ModerationKind $framework): bool
    {
        return $declared->title === $framework->title
            && $declared->input === $framework->input
            && $declared->reasons === $framework->reasons
            && $declared->defaultRules === $framework->defaultRules
            && $declared->verdictTo === $framework->verdictTo;
    }
}

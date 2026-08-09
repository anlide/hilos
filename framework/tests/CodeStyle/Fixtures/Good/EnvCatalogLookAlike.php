<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * Negative sample shaped like the real environment catalogs: a table mapping a
 * setting name to its default, where several independent settings happen to hold
 * the same number.
 *
 * It must stay silent. The key names each entry, so the number inside that entry
 * is that setting's data rather than an anonymous repeat — however deep in the
 * value it sits, and whether the value is a plain literal, a call carrying named
 * arguments, or a map folded into another one. A hit here means the exemption
 * narrowed and the catalogs of the framework and the demos would light up next.
 */
final class EnvCatalogLookAlike implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Catalog keyed by env variable name
     */
    public static function getCatalog(): array
    {
        return array_replace(self::sharedDefaults(), [
            'CHAT_MESSAGE_RETENTION_DAYS' => self::entry('integer', 90, emptyIsMissing: true),
            'CHAT_ATTACHMENT_RETENTION_DAYS' => self::entry('integer', 90, emptyIsMissing: true),
            'CHAT_MODERATION_RETENTION_DAYS' => self::entry(
                'integer',
                90,
                emptyIsMissing: true,
            ),
            'CHAT_AUDIT_RETENTION_DAYS' => self::entry('integer', 90),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>> Defaults every catalog starts from
     */
    private static function sharedDefaults(): array
    {
        return [
            'MAIL_TIMEOUT_MS' => self::entry('integer', 10000, emptyIsMissing: true),
            'SMS_TIMEOUT_MS' => self::entry('integer', 10000, emptyIsMissing: true),
        ];
    }

    /**
     * @param string $type Declared type of the setting
     * @param mixed $default Value the setting takes when the environment says nothing
     * @param bool $emptyIsMissing Whether an empty value reads as absent
     * @return array<string, mixed> One catalog entry
     */
    private static function entry(string $type, mixed $default, bool $emptyIsMissing = false): array
    {
        return ['type' => $type, 'default' => $default, 'empty_is_missing' => $emptyIsMissing];
    }
}

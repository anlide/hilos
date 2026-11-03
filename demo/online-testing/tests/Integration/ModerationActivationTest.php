<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tests\Integration;

use Demo\OnlineTesting\Database\Database;
use Demo\OnlineTesting\Hilos;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Moderation\ModerationKindKey;

/** Confirms moderation is activated without enabling profile photos. */
final class ModerationActivationTest extends IntegrationTestCase
{
    /** The schema has the moderation rules table and publishes only the profile-name kind. */
    public function testModerationIsAvailableWithoutProfilePhoto(): void
    {
        $units = array_column((new FrameworkSchemaCatalog())->selectedUnits(Hilos::class), null, 'key');
        self::assertSame(['hilos_moderation_rule'], $units['moderation_rule']->tables);
        Database::sql('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', ['hilos_moderation_rule']);
        self::assertSame(['id', 'kind', 'rule'], array_column(Database::rows(), 'COLUMN_NAME'));
        self::assertSame([ModerationKindKey::PROFILE_NAME], array_keys(Hilos::moderationKinds()));
        self::assertArrayNotHasKey(ModerationKindKey::PROFILE_PHOTO, Hilos::moderationKinds());
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationProjectRegistry;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\TestCase;

/** Reviewed repository artifacts; neither migration application nor legacy-track auto-adoption. */
final class FrameworkMigrationDemoAdoptionTest extends TestCase
{
    private const array GENESIS_UNITS = [
        'access_log', 'account_deletion', 'admin_view_mode_latch', 'analytics', 'auth_block', 'change_log',
        'country', 'country_name', 'data_export', 'database_marker', 'file', 'file_variant', 'i18n_reflow',
        'identity', 'language', 'language_name', 'legal_acceptance', 'legal_acceptance_export', 'locale',
        'migration', 'migration_claim', 'notification', 'notification_delivery', 'notification_preference',
        'oauth_provider', 'passkey_credential', 'push_subscription', 'registration_reservation', 'second_factor',
        'second_factor_backup_code', 'second_factor_reset', 'second_factor_setting', 'second_factor_trust',
        'session', 'setting', 'step_up', 'user', 'user_merge', 'user_photo', 'user_rename', 'user_verification', 'verifier_circle',
    ];

    public function testSixReviewedAdoptionsAndCurrentInstallsHaveExactCoverageAndRepeatWithoutNewFiles(): void
    {
        $root = dirname(__DIR__, 4);
        $projects = FrameworkMigrationProjectRegistry::load($root . '/framework-projects.json');
        self::assertSame([
            'Demo\\Chat\\Hilos', 'Demo\\Tasks\\Hilos', 'Demo\\Polls\\Hilos',
            'Demo\\BinanceBtcTracker\\Hilos', 'Demo\\EcommerceShop\\Hilos', 'Demo\\OnlineTesting\\Hilos',
        ], array_column($projects, 'facade'));
        $indices = [97, 53, 53, 43, 42, 42];
        $excluded = [
            [],
            ['notification_delivery', 'file', 'file_variant', 'user_photo'],
            ['notification_delivery', 'file', 'file_variant', 'user_photo'],
            ['file', 'file_variant', 'user_photo', 'oauth_provider', 'legal_acceptance_export'],
            ['notification_delivery', 'file', 'file_variant', 'user_photo', 'oauth_provider', 'legal_acceptance_export'],
            ['notification_delivery', 'file', 'file_variant', 'user_photo', 'oauth_provider', 'legal_acceptance_export'],
        ];
        $schema = new FrameworkSchemaCatalog();
        $catalog = new FrameworkMigrationCatalog($schema);
        foreach ($projects as $index => $project) {
            $base = $project->root . '/' . sprintf('%03d', $indices[$index]) . '_adopt_framework_catalog';
            $up = FsPath::read($base . '.sql');
            $down = FsPath::read($base . '_down.sql');
            $origin = $catalog->verifyPair($up, $down);
            self::assertSame(FrameworkMigrationOrigin::ADOPT, $origin->kind);
            self::assertSame(array_values(array_diff(self::GENESIS_UNITS, $excluded[$index])), $origin->units);
            self::assertSame([], $origin->parts);
            self::assertSame('', trim(preg_replace('/--[^\n]*/', '', $up)));
            self::assertSame('', trim(preg_replace('/--[^\n]*/', '', $down)));
            $origins = [$origin];
            if ($index >= 3) {
                foreach (['oauth_provider', 'legal_acceptance_export'] as $offset => $key) {
                    $unit = $schema->unit($key);
                    $base = $project->root . '/' . sprintf('%03d', $indices[$index] - 2 + $offset) . '_create_hilos_' . $key;
                    $up = FsPath::read($base . '.sql');
                    $down = FsPath::read($base . '_down.sql');
                    $install = $catalog->verifyPair($up, $down);
                    self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                    self::assertSame([$key], $install->units);
                    self::assertSame($catalog->partsForUnits([$key]), $install->parts);
                    self::assertSame(FsPath::read($unit->upPath), substr($up, strpos($up, "\n") + 1));
                    self::assertSame(FsPath::read($unit->downPath), substr($down, strpos($down, "\n") + 1));
                    $origins[] = $install;
                }
            }
            $base = $project->root . '/' . sprintf('%03d', $indices[$index] + 1)
                . '_framework_change_38c7203a140d8efdf40017ddaa16d4a695810303ef7f3e89ae703b19ed141c0d';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['user'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261021021039718057_HIL-1580_add_user_time_zone/user', $change->parts[0]->key());
            $origins[] = $change;
            self::assertSame([], $catalog->missingParts(array_column($schema->selectedUnits($project->facade), 'key'), $origins));
        }
        $plans = new FrameworkMigrationInstaller()->plan(...$projects);
        self::assertSame([[], [], [], [], [], []], array_column($plans, 'publications'));
    }
}

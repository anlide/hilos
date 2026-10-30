<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPart;
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
        $browserTimeZoneIndices = [99, 59, 59, 45, 45, 45];
        $statusIndices = [102, 61, 61, 47, 47, 47];
        $browserTimeZones = [
            'session' => '07a4fbd825b000f57571647973c8814230f60651773b8b19d4c8634b7dacf464',
            'user' => '57ecdc04ffd0dc9bad816b7d3eb0bd3cbe48486ffe4490ae42c25ec8339255da',
        ];
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
            foreach (array_keys($browserTimeZones) as $offset => $unit) {
                $base = $project->root . '/' . sprintf('%03d', $browserTimeZoneIndices[$index] + $offset)
                    . '_framework_change_' . $browserTimeZones[$unit];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$unit], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261022124515804554_HIL-1582_browser_time_zones/' . $unit, $change->parts[0]->key());
                $origins[] = $change;
            }
            if ($index === 0 || $index === 1) {
                // Chat takes the rules table for its seed; Tasks activates the framework moderator (HIL-1636).
                $unit = $schema->unit('moderation_rule');
                $base = $project->root . ($index === 0 ? '/101' : '/070') . '_framework_install_moderation_rule';
                $up = FsPath::read($base . '.sql');
                $down = FsPath::read($base . '_down.sql');
                $install = $catalog->verifyPair($up, $down);
                self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                self::assertSame(['moderation_rule'], $install->units);
                self::assertSame(
                    array_map(static fn (FrameworkMigrationPart $part): array => $part->toArray(), $catalog->partsForUnits(['moderation_rule'])),
                    array_map(static fn (FrameworkMigrationPart $part): array => $part->toArray(), $install->parts),
                );
                self::assertSame(FsPath::read($unit->upPath), substr($up, strpos($up, "\n") + 1));
                self::assertSame(FsPath::read($unit->downPath), substr($down, strpos($down, "\n") + 1));
                $origins[] = $install;
            }
            $statusParts = [
                $catalog->part('20261023115508508976_HIL-1688_status_machine', 'status_incident'),
                $catalog->part('20261023115508508976_HIL-1688_status_machine', 'status_incident_update'),
            ];
            foreach (['status_incident', 'status_incident_update'] as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $statusIndices[$index] + $offset) . '_framework_install_' . $key;
                $up = FsPath::read($base . '.sql');
                $down = FsPath::read($base . '_down.sql');
                $install = $catalog->verifyPair($up, $down);
                self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                self::assertSame([$key], $install->units);
                self::assertSame(
                    [$statusParts[$offset]->toArray()],
                    array_map(static fn (FrameworkMigrationPart $part): array => $part->toArray(), $install->parts),
                );
                self::assertSame($statusParts[$offset]->upSql, substr($up, strpos($up, "\n") + 1));
                self::assertSame($statusParts[$offset]->downSql, substr($down, strpos($down, "\n") + 1));
                $origins[] = $install;
            }
            $subscriberIndices = [104, 63, 63, 49, 49, 49];
            $base = $project->root . '/' . sprintf('%03d', $subscriberIndices[$index]) . '_framework_install_status_subscriber';
            $install = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
            self::assertSame(['status_subscriber'], $install->units);
            self::assertCount(1, $install->parts);
            $origins[] = $install;
            $base = $project->root . '/' . sprintf('%03d', $subscriberIndices[$index] + 1)
                . '_framework_change_be7c208b2260f84bd9588c1650acd50da2ee9b04bc2bda228dfcb69c106a0fe6';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['auth_block'], $change->units);
            self::assertCount(1, $change->parts);
            $origins[] = $change;
            $workIndices = [106, 65, 65, 51, 51, 51];
            foreach (['status_work', 'status_work_component', 'status_maintenance_period'] as $offset => $key) {
                $unit = $schema->unit($key);
                $base = $project->root . '/' . sprintf('%03d', $workIndices[$index] + $offset) . '_framework_install_' . $key;
                $up = FsPath::read($base . '.sql');
                $down = FsPath::read($base . '_down.sql');
                $install = $catalog->verifyPair($up, $down);
                self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                self::assertSame([$key], $install->units);
                self::assertCount(1, $install->parts);
                $source = $catalog->part($install->parts[0]->changeId, $key);
                self::assertSame(
                    [$source->toArray()],
                    array_map(static fn (FrameworkMigrationPart $part): array => $part->toArray(), $install->parts),
                );
                self::assertTrue($source->introducesUnit);
                self::assertSame(FsPath::read($unit->upPath), substr($up, strpos($up, "\n") + 1));
                self::assertSame(FsPath::read($unit->downPath), substr($down, strpos($down, "\n") + 1));
                $origins[] = $install;
            }
            $historyIndices = [109, 68, 68, 54, 54, 54];
            $base = $project->root . '/' . sprintf('%03d', $historyIndices[$index])
                . '_framework_change_313e4c04d843d20b85a3a36751ce9c28e0205866b4ca48df51362e6565afc3c0';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['status_incident'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261029183938100830_HIL-1693_status_history_order/status_incident', $change->parts[0]->key());
            $source = $catalog->part($change->parts[0]->changeId, 'status_incident');
            self::assertFalse($source->introducesUnit);
            self::assertSame(['20261023115508508976_HIL-1688_status_machine/status_incident'], $source->requires);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('status_incident')->upPath)),
                $source->afterStubUpSha256);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('status_incident')->downPath)),
                $source->afterStubDownSha256);
            $origins[] = $change;
            $identityIndices = [111, 69, 69, 55, 55, 55];
            $base = $project->root . '/' . sprintf('%03d', $identityIndices[$index])
                . '_framework_change_fb4f29fffa5ab99796ae4ab21bdc0abb8a1bf2cef68400e163599ac3328dfb62';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['identity'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261023065022000000_HIL-1653_identity_display_fields/identity', $change->parts[0]->key());
            $source = $catalog->part($change->parts[0]->changeId, 'identity');
            self::assertFalse($source->introducesUnit);
            self::assertSame([], $source->requires);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('identity')->upPath)),
                $source->afterStubUpSha256);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('identity')->downPath)),
                $source->afterStubDownSha256);
            $origins[] = $change;
            self::assertSame([], $catalog->missingParts(array_column($schema->selectedUnits($project->facade), 'key'), $origins));
        }
        $plans = new FrameworkMigrationInstaller()->plan(...$projects);
        self::assertSame([[], [], [], [], [], []], array_column($plans, 'publications'));
    }
}

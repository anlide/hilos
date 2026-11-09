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
        $genesis = array_column(
            json_decode(FsPath::read($root . '/framework/backend/Database/Migration/catalog.json'), true, flags: JSON_THROW_ON_ERROR)['units'],
            null,
            'unit',
        );
        $exportTimeZoneIndices = [112, 71, 71, 56, 56, 56];
        $exportTimeZones = [
            'data_export' => '6f7552ae23f9d48f528091ecebaab13fd22b0f314bf8f29e08832361b62bc5ba',
            'legal_acceptance_export' => 'ee22a8b08ebfa4d74799509ddb26680c80f2ac2c21e8aa15c9a2abf77183a922',
        ];
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
                // Both installs copied the genesis form; a later change part reaches them as a change pair.
                foreach (['oauth_provider', 'legal_acceptance_export'] as $offset => $key) {
                    $base = $project->root . '/' . sprintf('%03d', $indices[$index] - 2 + $offset) . '_create_hilos_' . $key;
                    $up = FsPath::read($base . '.sql');
                    $down = FsPath::read($base . '_down.sql');
                    $install = $catalog->verifyPair($up, $down);
                    self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                    self::assertSame([$key], $install->units);
                    self::assertSame([], $install->parts);
                    self::assertSame($genesis[$key]['upSha256'], FrameworkMigrationOrigin::bodyHash(substr($up, strpos($up, "\n") + 1)));
                    self::assertSame($genesis[$key]['downSha256'], FrameworkMigrationOrigin::bodyHash(substr($down, strpos($down, "\n") + 1)));
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
            if ($index === 0 || $index === 1 || $index === 2 || $index === 5) {
                // Chat installed rules for its seed; tasks, polls and online-testing use MODERATION.
                $unit = $schema->unit('moderation_rule');
                $migrationNumber = match ($index) {
                    0 => '101',
                    1, 2 => '070',
                    5 => '058',
                };
                $base = $project->root . '/' . $migrationNumber . '_framework_install_moderation_rule';
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
            foreach (array_keys($exportTimeZones) as $offset => $unit) {
                $base = $project->root . '/' . sprintf('%03d', $exportTimeZoneIndices[$index] + $offset)
                    . '_framework_change_' . $exportTimeZones[$unit];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$unit], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261101093551800031_HIL-1579_export_time_zones/' . $unit, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $unit);
                self::assertFalse($source->introducesUnit);
                self::assertSame([], $source->requires);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($unit)->upPath)), $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($unit)->downPath)), $source->afterStubDownSha256);
                $origins[] = $change;
            }
            if ($index === 5) {
                $unit = $schema->unit('probe_value');
                $base = $project->root . '/059_framework_install_probe_value';
                $up = FsPath::read($base . '.sql');
                $down = FsPath::read($base . '_down.sql');
                $install = $catalog->verifyPair($up, $down);
                self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                self::assertSame(['probe_value'], $install->units);
                self::assertCount(1, $install->parts);
                $source = $catalog->part($install->parts[0]->changeId, 'probe_value');
                self::assertTrue($source->introducesUnit);
                self::assertSame([], $source->requires);
                self::assertSame([$source->toArray()], array_map(
                    static fn (FrameworkMigrationPart $part): array => $part->toArray(),
                    $install->parts,
                ));
                self::assertSame(FsPath::read($unit->upPath), substr($up, strpos($up, "\n") + 1));
                self::assertSame(FsPath::read($unit->downPath), substr($down, strpos($down, "\n") + 1));
                $origins[] = $install;
            } else {
                self::assertSame([], glob($project->root . '/*_framework_install_probe_value*.sql'));
                self::assertNotContains('probe_value', array_column($schema->selectedUnits($project->facade), 'key'));
            }
            $deliveryIndices = [117, 75, 75, 60, 60, 62];
            $base = $project->root . '/' . sprintf('%03d', $deliveryIndices[$index])
                . '_framework_change_869cfec607df616589031585e78bbd31d915dc4d908729ff59aefc127be19f58';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['notification_delivery'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261108080825608007_HIL-1893_delivery_routing_key/notification_delivery', $change->parts[0]->key());
            $source = $catalog->part($change->parts[0]->changeId, 'notification_delivery');
            self::assertFalse($source->introducesUnit);
            self::assertSame([], $source->requires);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('notification_delivery')->upPath)),
                $source->afterStubUpSha256);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('notification_delivery')->downPath)),
                $source->afterStubDownSha256);
            $origins[] = $change;
            $erasureIndices = [115, 73, 73, 58, 58, 60];
            foreach (['account_erasure_operation', 'account_erasure_assignment'] as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $erasureIndices[$index] + $offset) . '_framework_install_' . $key;
                $up = FsPath::read($base . '.sql');
                $down = FsPath::read($base . '_down.sql');
                $install = $catalog->verifyPair($up, $down);
                self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
                self::assertSame([$key], $install->units);
                self::assertCount(1, $install->parts);
                $source = $catalog->part($install->parts[0]->changeId, $key);
                self::assertSame('20261108074007375591_HIL-1936_account_erasure_storage' . '/' . $key, $source->key());
                self::assertTrue($source->introducesUnit);
                self::assertSame([$source->toArray()], array_map(
                    static fn (FrameworkMigrationPart $part): array => $part->toArray(),
                    $install->parts,
                ));
                self::assertSame($source->afterStubUpSha256, FrameworkMigrationOrigin::bodyHash(substr($up, strpos($up, "\n") + 1)));
                self::assertSame($source->afterStubDownSha256, FrameworkMigrationOrigin::bodyHash(substr($down, strpos($down, "\n") + 1)));
                $origins[] = $install;
            }
            $resultIndices = [118, 76, 76, 61, 61, 63];
            $base = $project->root . '/' . sprintf('%03d', $resultIndices[$index]) . '_framework_install_account_erasure_result';
            $up = FsPath::read($base . '.sql');
            $down = FsPath::read($base . '_down.sql');
            $install = $catalog->verifyPair($up, $down);
            self::assertSame(FrameworkMigrationOrigin::INSTALL, $install->kind);
            self::assertSame(['account_erasure_result'], $install->units);
            self::assertCount(1, $install->parts);
            $source = $catalog->part($install->parts[0]->changeId, 'account_erasure_result');
            self::assertSame('20261108210743046351_HIL-1937_account_erasure_results/account_erasure_result', $source->key());
            self::assertTrue($source->introducesUnit);
            self::assertSame([$source->toArray()], array_map(
                static fn (FrameworkMigrationPart $part): array => $part->toArray(),
                $install->parts,
            ));
            self::assertSame($source->afterStubUpSha256, FrameworkMigrationOrigin::bodyHash(substr($up, strpos($up, "\n") + 1)));
            self::assertSame($source->afterStubDownSha256, FrameworkMigrationOrigin::bodyHash(substr($down, strpos($down, "\n") + 1)));
            $origins[] = $install;
            $bootstrapIndices = [119, 77, 77, 62, 62, 64];
            $bootstrapParts = [
                'admin_view_mode_latch' => 'b6f9ace5d003c5e5a222f56631950869f18630a9d7c408f6d5824baca0ff84a6',
                'database_marker' => '28592ca92f7cd14e890e1cf6687a6d1b07d42cfe000870332b705fb759a28d55',
                'migration_claim' => 'a21d019f0c9b7985932ee44d24d39c6ab3be9fbb784b00f8b7914ba04682543b',
            ];
            foreach (array_keys($bootstrapParts) as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $bootstrapIndices[$index] + $offset)
                    . '_framework_change_' . $bootstrapParts[$key];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261109000848893922_HIL-1918_bootstrap_moments/' . $key, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame([], $source->requires);
                self::assertNull($source->afterStubUpSha256);
                self::assertNull($source->afterStubDownSha256);
                self::assertNull($schema->unit($key)->upPath);
                self::assertNull($schema->unit($key)->downPath);
                $origins[] = $change;
            }
            $operationIndices = [122, 80, 80, 65, 65, 67];
            $operationParts = [
                '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_operation'
                    => '7555e2e059f8d281ee25405b9a970796528d46c4c8d6548c6193a5238a78e91b',
                '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_assignment'
                    => '7afd41034fd719f8c1ef5ec2a4d35fe71a4a6d339be3f3dfb9010c85dd77d003',
                '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_result'
                    => '3336d60036170fdabd0cd21e1d0433a619b02550e68b50ecbb683cd33dc8216c',
                '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_result'
                    => 'b0f4161d453f2f3c4fa5ff77d6be845420883999602165c4ee446bb73101e615',
                '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_assignment'
                    => '085f93251c72f6936bd6c760db53cfabf95a744ed6f9e00a5481466ba64d54af',
                '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_operation'
                    => 'b64e0f7211f15188ca3391cc49e3383760b5cce95420acfdad9e2f623844e1b2',

            ];
            foreach (array_keys($operationParts) as $offset => $partKey) {
                [$changeId, $key] = explode('/', $partKey);
                $base = $project->root . '/' . sprintf('%03d', $operationIndices[$index] + $offset)
                    . '_framework_change_' . $operationParts[$partKey];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame($partKey, $change->parts[0]->key());
                $source = $catalog->part($changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                    $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                    $source->afterStubDownSha256);
                $origins[] = $change;
            }
            $momentIndices = [128, 86, 86, 71, 71, 73];
            $momentParts = [
                '20261109120150360881_HIL-1919_session_moments/verifier_circle'
                    => '05d494466ac3a8dd1c8e1e6f7809d9f96d14cd89f991bb9a07e5b9c1f1b7c948',
                '20261109120150360881_HIL-1919_session_moments/session'
                    => 'aa31db5ae2cee3bd8d3e639ef921281b10620f82f13f4122e5bd0c7b78993de7',
            ];
            foreach (array_keys($momentParts) as $offset => $partKey) {
                [$changeId, $key] = explode('/', $partKey);
                $base = $project->root . '/' . sprintf('%03d', $momentIndices[$index] + $offset)
                    . '_framework_change_' . $momentParts[$partKey];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame($partKey, $change->parts[0]->key());
                $source = $catalog->part($changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                    $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                    $source->afterStubDownSha256);
                $origins[] = $change;
            }
            if ($index < 3) {
                $fileIndices = [130, 88, 88];
                $fileParts = [
                    'file' => '4e60a3b0f879d84bcc5d0def1f5f5e94c7505c7ea53fcb6583ba23eaf4fa4025',
                    'file_variant' => 'b7a43257c371381a444d7cb8d1d82331d8f4524a3396d2dd4874e79e9ecad2c3',
                ];
                foreach (array_keys($fileParts) as $offset => $key) {
                    $base = $project->root . '/' . sprintf('%03d', $fileIndices[$index] + $offset)
                        . '_framework_change_' . $fileParts[$key];
                    $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                    self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                    self::assertSame([$key], $change->units);
                    self::assertCount(1, $change->parts);
                    self::assertSame('20261109114206000000_HIL-1926_file_moments/' . $key, $change->parts[0]->key());
                    $source = $catalog->part($change->parts[0]->changeId, $key);
                    self::assertFalse($source->introducesUnit);
                    self::assertSame([], $source->requires);
                    self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                        $source->afterStubUpSha256);
                    self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                        $source->afterStubDownSha256);
                    $origins[] = $change;
                }
            }
            self::assertSame([], $catalog->missingParts(array_column($schema->selectedUnits($project->facade), 'key'), $origins));
        }
        $plans = new FrameworkMigrationInstaller()->plan(...$projects);
        self::assertSame([[], [], [], [], [], []], array_column($plans, 'publications'));
    }
}

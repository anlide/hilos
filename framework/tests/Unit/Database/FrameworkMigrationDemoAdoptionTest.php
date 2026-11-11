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

    /** Keep historical origins fixed while requiring exact current coverage across each complete track. */
    public function testReviewedAdoptionsAndBaselineHaveExactCoverageAndRepeatWithoutNewFiles(): void
    {
        $root = dirname(__DIR__, 4);
        $projects = FrameworkMigrationProjectRegistry::load($root . '/framework-projects.json');
        self::assertSame([
            'Demo\\Chat\\Hilos', 'Demo\\Tasks\\Hilos', 'Demo\\Polls\\Hilos',
            'Demo\\BinanceBtcTracker\\Hilos', 'Demo\\EcommerceShop\\Hilos', 'Demo\\OnlineTesting\\Hilos',
            'Demo\\Preferans\\Hilos',
            'Demo\\Pmd\\Hilos',
            'Demo\\ConcertTickets\\Hilos',
            'Demo\\SolarSystemModel\\Hilos',
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
        // PMD (index 7) keeps its unchecked track; Concert Tickets (index 8) is a native baseline.
        foreach (array_slice($projects, 0, 7) + [8 => $projects[8]] as $index => $project) {
            if ($index === 8) {
                $this->assertConcertTicketsNativeBaseline($project->root, $project->facade, $catalog, $schema);
                continue;
            }
            if ($index === 6) {
                $base = $project->root . '/001_framework_baseline';
                $origin = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::BASELINE, $origin->kind);
                self::assertSame([
                    'access_log', 'account_deletion', 'account_erasure_assignment', 'account_erasure_operation',
                    'account_erasure_result', 'admin_view_mode_latch', 'analytics', 'country', 'country_name',
                    'data_export', 'database_marker', 'i18n_reflow', 'identity', 'language', 'language_name',
                    'legal_acceptance', 'legal_acceptance_export', 'locale', 'migration', 'migration_claim',
                    'notification', 'notification_delivery', 'notification_preference', 'oauth_provider',
                    'passkey_credential', 'push_subscription', 'registration_reservation', 'second_factor',
                    'second_factor_backup_code', 'second_factor_reset', 'second_factor_setting', 'second_factor_trust',
                    'session', 'setting', 'step_up', 'user', 'user_merge', 'user_rename', 'user_verification', 'verifier_circle',
                ], $origin->units);
                self::assertSame('521ea376d5905558e1bc9f1243c9b9ab47950670d828662f05282e649f6d9c0f', $origin->upSha256);
                self::assertSame('248362e38b91114315d084158e8cad7c1159e4a3e40147b2c917969547968fd6', $origin->downSha256);
                $baselinePartKeys = [
                    '20261021021039718057_HIL-1580_add_user_time_zone/user',
                    '20261022124515804554_HIL-1582_browser_time_zones/session',
                    '20261022124515804554_HIL-1582_browser_time_zones/user',
                    '20261023065022000000_HIL-1653_identity_display_fields/identity',
                    '20261101093551800031_HIL-1579_export_time_zones/data_export',
                    '20261101093551800031_HIL-1579_export_time_zones/legal_acceptance_export',
                    '20261108074007375591_HIL-1936_account_erasure_storage/account_erasure_assignment',
                    '20261108074007375591_HIL-1936_account_erasure_storage/account_erasure_operation',
                    '20261108080825608007_HIL-1893_delivery_routing_key/notification_delivery',
                    '20261108210743046351_HIL-1937_account_erasure_results/account_erasure_result',
                    '20261109000848893922_HIL-1918_bootstrap_moments/admin_view_mode_latch',
                    '20261109000848893922_HIL-1918_bootstrap_moments/database_marker',
                    '20261109000848893922_HIL-1918_bootstrap_moments/migration_claim',
                    '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_assignment',
                    '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_operation',
                    '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_result',
                    '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_assignment',
                    '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_operation',
                    '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_result',
                    '20261109120150360881_HIL-1919_session_moments/session',
                    '20261109120150360881_HIL-1919_session_moments/verifier_circle',
                    '20261109185924000000_HIL-1927_analytics_signed_moments/analytics',
                    '20261110021316313226_HIL-1923_notification_moments/notification',
                    '20261110021316313226_HIL-1923_notification_moments/notification_delivery',
                    '20261110021316313226_HIL-1923_notification_moments/notification_preference',
                    '20261110021316313226_HIL-1923_notification_moments/push_subscription',
                ];
                $baselineParts = array_map(static function (string $key) use ($catalog): FrameworkMigrationPart {
                    [$changeId, $unit] = explode('/', $key);

                    return $catalog->part($changeId, $unit);
                }, $baselinePartKeys);
                self::assertSame($this->partRecords($baselineParts), $this->partRecords($origin->parts));
                $laterParts = [
                    'access_log' => [
                        'changeId' => '20261110024242374685_HIL-1924_people_moments',
                        'file' => '002_framework_change_c7ff605f8c01200ff32f0076a3fda33da471236004443ec9eabf0cc20c482a17',
                        'upSha256' => '985119b2b3087d2a79507f6488053d92f4ef5bca0b93ea44559ffc152fa2f739',
                        'downSha256' => '97c6dc54aa24641cc4261656213525dde52fd3e43bbf0ba7c1905717b992df8f',
                        'requires' => [],
                    ],
                    'account_deletion' => [
                        'changeId' => '20261110024242374685_HIL-1924_people_moments',
                        'file' => '003_framework_change_2c19121389061e61ddbe804fb2d8d64869cf13b5a44fc40c6eff8d698a870920',
                        'upSha256' => '5474c8b99adbcff899089e668bf7f4d36f3d1ca9b7a762bcdfe8f22fab944f2d',
                        'downSha256' => '4199b444b0f6156749e13becb8345a24c429fa18cbad48a0866b177fede49168',
                        'requires' => [],
                    ],
                    'user' => [
                        'changeId' => '20261110024242374685_HIL-1924_people_moments',
                        'file' => '004_framework_change_4b5c4cd893d23a7b3d80d332f1b9736887ba3b3f140d2a4bf88ca6760a0f8edf',
                        'upSha256' => '7ae00a3b4fa3d9d136d4c7785bb0ba3fcbed7b1b39dc8c82c7930b3b3a47c51f',
                        'downSha256' => '2703ac0fb347a90eeabc665e55ca4768dbcf8fa54a6723f0974af4317cefa419',
                        'requires' => ['20261022124515804554_HIL-1582_browser_time_zones/user'],
                    ],
                    'user_merge' => [
                        'changeId' => '20261110024242374685_HIL-1924_people_moments',
                        'file' => '005_framework_change_0901c07b990c53069c79ba8694c6da343bd217cfe51beb92f1ba1a004535100c',
                        'upSha256' => '3c8759e3317adb6b134393b5ee7e5e53c915d8c0f8ba0eed78712bdf6c0f7aa4',
                        'downSha256' => '798a3786c4ac7e5da18e8ded7ac5730fd4f405b14b753011cc81e2f754fdaf96',
                        'requires' => [],
                    ],
                    'user_rename' => [
                        'changeId' => '20261110024242374685_HIL-1924_people_moments',
                        'file' => '006_framework_change_c929a65b572002203b2611d8cb0bddcfe66a08df04cd8a6298ea9b50ef54cb8c',
                        'upSha256' => '9c9dbed3f3eb27fb01f4184eb9ba36b0bec2021157c617d81bd7238e0086c234',
                        'downSha256' => '41ae942462cb133e422a04f20dad34e71b8547464fc83151238b454bcd8be53b',
                        'requires' => [],
                    ],
                    'identity' => [
                        'changeId' => '20261110014712983573_HIL-1920_sign_in_moments',
                        'file' => '007_framework_change_23ffd88b4e80df8cab33c4058276195ed1510da449aaaade6637237219830a47',
                        'upSha256' => '8734c4b591797b213be5ad64cd6f39c0bf0ecd6d303ed0ef3aa4f17954d830ab',
                        'downSha256' => '4f9034b57aff3dd5ea6cbc19fc9675ff67ce7dab81ce5286d458cff1be4cbbbe',
                        'requires' => ['20261023065022000000_HIL-1653_identity_display_fields/identity'],
                    ],
                    'oauth_provider' => [
                        'changeId' => '20261110014712983573_HIL-1920_sign_in_moments',
                        'file' => '008_framework_change_459b21cbd9183b9fa308440ab4ea0165ccbf1f9fa80825f1042831270e957f12',
                        'upSha256' => '00101162d077a303ff19fcf63dfc46cf939c8c46456cc116fc363db053ad55f7',
                        'downSha256' => '89e0850764c106553b179088237d5ffb88b4c39de912b018e059dbf306a52b2d',
                        'requires' => [],
                    ],
                    'passkey_credential' => [
                        'changeId' => '20261110014712983573_HIL-1920_sign_in_moments',
                        'file' => '009_framework_change_f94e7f65cc568746bc4d64c19144c308b5b6699773dd6f3a80738c6486dc0825',
                        'upSha256' => '69e8005e59e892f37dffd790a24c23e5068941486f2bd609a0dfe2e7e80a7337',
                        'downSha256' => 'b3f2b3468ce4417b40ab4076ee981f2445f0d40c44b81671e26dc2ad78a58ee7',
                        'requires' => [],
                    ],
                    'registration_reservation' => [
                        'changeId' => '20261111013234414363_HIL-1921_auth_proof_moments',
                        'file' => '010_framework_change_27033a7bf9ae66048bcc04566b4c41aba9b8b6000c7a666b82cd202ffe04370f',
                        'upSha256' => '2f033a4371e967263542bf55a0e77b259614e483ac9ed8d0b92d4cf9181d5706',
                        'downSha256' => '41ac12805344609e97df200b45aadf24336923a0b2e4a2f058804b1ecd0c3454',
                        'requires' => [],
                    ],
                    'step_up' => [
                        'changeId' => '20261111013234414363_HIL-1921_auth_proof_moments',
                        'file' => '011_framework_change_b4527b9f93fc948e9b047e9950ae158ae4b5db368354f091f3d62222a1ca87e3',
                        'upSha256' => 'ac967b1aa091fbc6eba71c25e7a6cf216c772d70d9d2e20ea80ac0a51f0f2419',
                        'downSha256' => '96b3ccc611dc67297027710ab03dd04e73778aa02354a9becccdb739d0287550',
                        'requires' => [],
                    ],
                    'user_verification' => [
                        'changeId' => '20261111013234414363_HIL-1921_auth_proof_moments',
                        'file' => '012_framework_change_ade9f4dadae9aa9ff1ce297458703d8d1c8495cd46bda0ea06cddcceccec08f4',
                        'upSha256' => '3a3dc55150209489b68a820f56e6ff2c54ce6b86f6829475083cba7953b9e218',
                        'downSha256' => '963d4af66e35bb34658aaa7e1d83a325543ba6442eb5d94aafd7edd77cb367a3',
                        'requires' => [],
                    ],
                    'push_subscription' => [
                        'changeId' => '20261111062211778865_HIL-1895_push_subscription_revision',
                        'file' => '013_framework_change_c12437770c3603bc651bbe5e433c0e256152f4bf69e514b554f40980cf472469',
                        'upSha256' => 'e72096f39952436de92a20a20c9e8ca8a3ba3256f2f13c064e8ae493ce7bc22e',
                        'downSha256' => 'd7276ca07e8844fd7267ae973c0be04fc3428635bb729b19df1dc75d6cd52551',
                        'requires' => ['20261110021316313226_HIL-1923_notification_moments/push_subscription'],
                    ],
                ];
                $origins = [$origin];
                $coveredParts = $origin->parts;
                foreach ($laterParts as $unit => $expected) {
                    $base = $project->root . '/' . $expected['file'];
                    $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                    $changeId = $expected['changeId'];
                    self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                    self::assertSame([$unit], $change->units);
                    self::assertCount(1, $change->parts);
                    self::assertSame($changeId, $change->parts[0]->changeId);
                    self::assertSame($changeId . '/' . $unit, $change->parts[0]->key());
                    $source = $catalog->part($changeId, $unit);
                    self::assertSame([$source->toArray()], $this->partRecords($change->parts));
                    self::assertSame($expected['upSha256'], $source->upSha256);
                    self::assertSame($expected['downSha256'], $source->downSha256);
                    self::assertSame($expected['upSha256'], $change->upSha256);
                    self::assertSame($expected['downSha256'], $change->downSha256);
                    self::assertFalse($source->introducesUnit);
                    self::assertSame($expected['requires'], $source->requires);
                    $origins[] = $change;
                    $coveredParts[] = $change->parts[0];
                }
                $selectedUnits = array_column($schema->selectedUnits($project->facade), 'key');
                self::assertSame(
                    $this->partRecords($catalog->partsForUnits($selectedUnits)),
                    $this->partRecords($coveredParts),
                );
                self::assertSame([], $catalog->missingParts($selectedUnits, $origins));
                continue;
            }
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
                self::assertSame($source->afterStubUpSha256, FrameworkMigrationOrigin::bodyHash(substr($up, strpos($up, "\n") + 1)));
                self::assertSame($source->afterStubDownSha256, FrameworkMigrationOrigin::bodyHash(substr($down, strpos($down, "\n") + 1)));
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
            $origins[] = $change;
            $signInIndices = [149, 107, 107, 88, 88, 90];
            $signInParts = [
                'identity' => '23ffd88b4e80df8cab33c4058276195ed1510da449aaaade6637237219830a47',
                'oauth_provider' => '459b21cbd9183b9fa308440ab4ea0165ccbf1f9fa80825f1042831270e957f12',
                'passkey_credential' => 'f94e7f65cc568746bc4d64c19144c308b5b6699773dd6f3a80738c6486dc0825',
            ];
            foreach (array_keys($signInParts) as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $signInIndices[$index] + $offset)
                    . '_framework_change_' . $signInParts[$key];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261110014712983573_HIL-1920_sign_in_moments/' . $key, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame($key === 'identity' ? ['20261023065022000000_HIL-1653_identity_display_fields/identity'] : [],
                    $source->requires);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                    $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                    $source->afterStubDownSha256);
                $origins[] = $change;
            }
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
            $source = $catalog->part('20261110021316313226_HIL-1923_notification_moments', 'notification_delivery');
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
            $analyticsIndices = [132, 90, 90, 73, 73, 75];
            $base = $project->root . '/' . sprintf('%03d', $analyticsIndices[$index])
                . '_framework_change_05044d42f8432ce611ef42bf1e29c58bf7b099a4650055ff2c2a115a03e192ef';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['analytics'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261109185924000000_HIL-1927_analytics_signed_moments/analytics', $change->parts[0]->key());
            $source = $catalog->part($change->parts[0]->changeId, 'analytics');
            self::assertFalse($source->introducesUnit);
            self::assertSame([], $source->requires);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('analytics')->upPath)),
                $source->afterStubUpSha256);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('analytics')->downPath)),
                $source->afterStubDownSha256);
            $origins[] = $change;
            $statusMomentIndices = [133, 91, 91, 74, 74, 76];
            $statusMomentParts = [
                'status_incident' => [
                    'sha' => 'a18d93d33c80b1e95083e4c7824ab7e8bc82a6d3bd58d5d94cb1c9c23b2fbafe',
                    'requires' => ['20261029183938100830_HIL-1693_status_history_order/status_incident'],
                ],
                'status_incident_update' => [
                    'sha' => '2eb8efa9f78619a68190b2acbc342be1b7fb8c13b58e98cddc5ffef7b74ab09b',
                    'requires' => ['20261023115508508976_HIL-1688_status_machine/status_incident_update'],
                ],
                'status_maintenance_period' => [
                    'sha' => '3e4923a290d5ccea9ef5e30cffd82631913cf88ce756548a1697c7630b608b8b',
                    'requires' => ['20261029024734382401_HIL-1692_status_works/status_maintenance_period'],
                ],
                'status_subscriber' => [
                    'sha' => 'e7873ec5c042f5f4fc6271315ed3ce06bbb7f2f163f2db79f77a24be4b7988ef',
                    'requires' => ['20261028002725141545_HIL-1691_status_subscribers/status_subscriber'],
                ],
                'status_work' => [
                    'sha' => 'b073557d07eb3771122d548f8d8d6388823ac40c664d51910ad6de980b04aa4b',
                    'requires' => ['20261029024734382401_HIL-1692_status_works/status_work'],
                ],
            ];
            foreach (array_keys($statusMomentParts) as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $statusMomentIndices[$index] + $offset)
                    . '_framework_change_' . $statusMomentParts[$key]['sha'];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261109203922000000_HIL-1928_status_signed_moments/' . $key, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame($statusMomentParts[$key]['requires'], $source->requires);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                    $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                    $source->afterStubDownSha256);
                $origins[] = $change;
            }
            $notificationMomentIndices = [138, 96, 96, 79, 79, 81];
            $notificationMomentParts = [
                'notification' => '916b7137a40990c7b497af332c39d28f312d41c85875827778de2347d918966c',
                'notification_delivery' => 'c2d46f3172a3b8972828d647827e38c06e65d97ea8d1b96d26dcc36dc35dfb39',
                'notification_preference' => '2c777b8c4cccc93b7320ee8854bfdc47cb9e5bcf6ee35e2d1399f6dd727f7792',
                'push_subscription' => '28d249b2d8f88aa643ce12eb72f4c471687eee5b9df9d23470fcffcb553696fa',
            ];
            foreach (array_keys($notificationMomentParts) as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $notificationMomentIndices[$index] + $offset)
                    . '_framework_change_' . $notificationMomentParts[$key];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261110021316313226_HIL-1923_notification_moments/' . $key, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame($key === 'notification_delivery'
                    ? ['20261108080825608007_HIL-1893_delivery_routing_key/notification_delivery'] : [], $source->requires);
                if ($key === 'push_subscription') {
                    self::assertSame('c536ba46e83beccb57a7088f7739b308e15fcc5d97372dd96e510f8e58727658', $source->afterStubUpSha256);
                    self::assertSame('854eda7a426d1cf2cb89d4680c1642f819de5281b275d4927cce08c47613e067', $source->afterStubDownSha256);
                } else {
                    self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                        $source->afterStubUpSha256);
                    self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                        $source->afterStubDownSha256);
                }
                $origins[] = $change;
            }
            $personMomentIndices = [
                [148, 143, 144, 145, 146, 147],
                [106, 101, 102, 103, 104, 105],
                [106, 101, 102, 103, 104, 105],
                [83, 84, 85, 86, 87],
                [83, 84, 85, 86, 87],
                [85, 86, 87, 88, 89],
            ];
            $personMomentParts = [
                'access_log' => 'c7ff605f8c01200ff32f0076a3fda33da471236004443ec9eabf0cc20c482a17',
                'account_deletion' => '2c19121389061e61ddbe804fb2d8d64869cf13b5a44fc40c6eff8d698a870920',
                'user' => '4b5c4cd893d23a7b3d80d332f1b9736887ba3b3f140d2a4bf88ca6760a0f8edf',
                'user_merge' => '0901c07b990c53069c79ba8694c6da343bd217cfe51beb92f1ba1a004535100c',
                'user_photo' => '23327e24babac176be21c35f01af1dbf9af0206375fb8855cbffbdcd5c2ec67c',
                'user_rename' => 'c929a65b572002203b2611d8cb0bddcfe66a08df04cd8a6298ea9b50ef54cb8c',
            ];
            $offset = 0;
            foreach ($personMomentParts as $key => $sha) {
                if ($key === 'user_photo' && $index >= 3) {
                    self::assertNotContains($key, array_column($schema->selectedUnits($project->facade), 'key'));
                    continue;
                }
                $base = $project->root . '/' . sprintf('%03d', $personMomentIndices[$index][$offset++])
                    . '_framework_change_' . $sha;
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261110024242374685_HIL-1924_people_moments/' . $key, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame($key === 'user'
                    ? ['20261022124515804554_HIL-1582_browser_time_zones/user'] : [], $source->requires);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                    $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                    $source->afterStubDownSha256);
                $origins[] = $change;
            }
            $journalMomentIndices = [152, 110, 110, 91, 91, 93];
            $base = $project->root . '/' . sprintf('%03d', $journalMomentIndices[$index])
                . '_framework_change_cbdda9664ec2eb54af415cfdb03fd0e3fddb4fae654af1f9935f10d886dbc9b3';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['change_log'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261110164648748072_HIL-1929_journal_moments/change_log', $change->parts[0]->key());
            $source = $catalog->part($change->parts[0]->changeId, 'change_log');
            self::assertFalse($source->introducesUnit);
            self::assertSame([], $source->requires);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('change_log')->upPath)),
                $source->afterStubUpSha256);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('change_log')->downPath)),
                $source->afterStubDownSha256);
            $origins[] = $change;
            $authProofIndices = [154, 111, 111, 92, 92, 94];
            $authProofParts = [
                'auth_block' => '318fc5c4d0186fd9201bc8123f2779f5e72e7c61cabcd038155fbf31c8f567dd',
                'registration_reservation' => '27033a7bf9ae66048bcc04566b4c41aba9b8b6000c7a666b82cd202ffe04370f',
                'step_up' => 'b4527b9f93fc948e9b047e9950ae158ae4b5db368354f091f3d62222a1ca87e3',
                'user_verification' => 'ade9f4dadae9aa9ff1ce297458703d8d1c8495cd46bda0ea06cddcceccec08f4',
            ];
            foreach (array_keys($authProofParts) as $offset => $key) {
                $base = $project->root . '/' . sprintf('%03d', $authProofIndices[$index] + $offset)
                    . '_framework_change_' . $authProofParts[$key];
                $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
                self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
                self::assertSame([$key], $change->units);
                self::assertCount(1, $change->parts);
                self::assertSame('20261111013234414363_HIL-1921_auth_proof_moments/' . $key, $change->parts[0]->key());
                $source = $catalog->part($change->parts[0]->changeId, $key);
                self::assertFalse($source->introducesUnit);
                self::assertSame($key === 'auth_block'
                    ? ['20261028002725141545_HIL-1691_status_subscribers/auth_block'] : [], $source->requires);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->upPath)),
                    $source->afterStubUpSha256);
                self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit($key)->downPath)),
                    $source->afterStubDownSha256);
                $origins[] = $change;
            }
            $pushRevisionIndices = [158, 115, 115, 96, 96, 98];
            $base = $project->root . '/' . sprintf('%03d', $pushRevisionIndices[$index])
                . '_framework_change_c12437770c3603bc651bbe5e433c0e256152f4bf69e514b554f40980cf472469';
            $change = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
            self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
            self::assertSame(['push_subscription'], $change->units);
            self::assertCount(1, $change->parts);
            self::assertSame('20261111062211778865_HIL-1895_push_subscription_revision/push_subscription', $change->parts[0]->key());
            $source = $catalog->part($change->parts[0]->changeId, 'push_subscription');
            self::assertFalse($source->introducesUnit);
            self::assertSame(
                ['20261110021316313226_HIL-1923_notification_moments/push_subscription'],
                $source->requires,
            );
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('push_subscription')->upPath)),
                $source->afterStubUpSha256);
            self::assertSame(FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('push_subscription')->downPath)),
                $source->afterStubDownSha256);
            $origins[] = $change;
            self::assertSame([], $catalog->missingParts(array_column($schema->selectedUnits($project->facade), 'key'), $origins));
        }
        $plans = new FrameworkMigrationInstaller()->plan(...$projects);
        self::assertSame([[], [], [], [], [], [], [], [], [], []], array_column($plans, 'publications'));
    }

    /**
     * Concert Tickets started on one native baseline written from its features and nothing after it.
     *
     * The pair is historical: its units, both body hashes and every change part folded into it stay
     * as they were reviewed, and a later framework change reaches the track as its own CHANGE pair.
     *
     * @param string $root Migration track of the project
     * @param string $facade Project facade class
     * @param FrameworkMigrationCatalog $catalog Framework change catalog
     * @param FrameworkSchemaCatalog $schema Framework schema catalog
     */
    private function assertConcertTicketsNativeBaseline(
        string $root,
        string $facade,
        FrameworkMigrationCatalog $catalog,
        FrameworkSchemaCatalog $schema,
    ): void {
        $base = $root . '/001_framework_baseline';
        $origin = $catalog->verifyPair(FsPath::read($base . '.sql'), FsPath::read($base . '_down.sql'));
        self::assertSame(FrameworkMigrationOrigin::BASELINE, $origin->kind);
        self::assertSame([
            'access_log',
            'account_deletion',
            'account_erasure_assignment',
            'account_erasure_operation',
            'account_erasure_result',
            'admin_view_mode_latch',
            'analytics',
            'change_log',
            'data_export',
            'database_marker',
            'file',
            'file_variant',
            'identity',
            'legal_acceptance',
            'legal_acceptance_export',
            'migration',
            'migration_claim',
            'moderation_rule',
            'notification',
            'notification_delivery',
            'notification_preference',
            'oauth_provider',
            'passkey_credential',
            'push_subscription',
            'registration_reservation',
            'second_factor',
            'second_factor_backup_code',
            'second_factor_reset',
            'second_factor_setting',
            'second_factor_trust',
            'session',
            'setting',
            'step_up',
            'user',
            'user_merge',
            'user_photo',
            'user_rename',
            'user_verification',
            'verifier_circle',
        ], $origin->units);
        self::assertSame('4ca274e363aa0f4799db61a73de73c0e2ad56d17f0d0f88eb1fb106bb622a847', $origin->upSha256);
        self::assertSame('ce447a1cd61c1408da06c15430070166778a37b56ca63312789d72acfbb2f234', $origin->downSha256);
        $baselinePartKeys = [
            '20261021021039718057_HIL-1580_add_user_time_zone/user',
            '20261022124515804554_HIL-1582_browser_time_zones/session',
            '20261022124515804554_HIL-1582_browser_time_zones/user',
            '20261022205657000000_HIL-1624_add_moderation_rules/moderation_rule',
            '20261023065022000000_HIL-1653_identity_display_fields/identity',
            '20261101093551800031_HIL-1579_export_time_zones/data_export',
            '20261101093551800031_HIL-1579_export_time_zones/legal_acceptance_export',
            '20261108074007375591_HIL-1936_account_erasure_storage/account_erasure_assignment',
            '20261108074007375591_HIL-1936_account_erasure_storage/account_erasure_operation',
            '20261108080825608007_HIL-1893_delivery_routing_key/notification_delivery',
            '20261108210743046351_HIL-1937_account_erasure_results/account_erasure_result',
            '20261109000848893922_HIL-1918_bootstrap_moments/admin_view_mode_latch',
            '20261109000848893922_HIL-1918_bootstrap_moments/database_marker',
            '20261109000848893922_HIL-1918_bootstrap_moments/migration_claim',
            '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_assignment',
            '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_operation',
            '20261109072435317521_HIL-1987_account_operation_tables/account_erasure_result',
            '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_assignment',
            '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_operation',
            '20261109072435317522_HIL-1987_drop_account_erasure_tables/account_erasure_result',
            '20261109114206000000_HIL-1926_file_moments/file',
            '20261109114206000000_HIL-1926_file_moments/file_variant',
            '20261109120150360881_HIL-1919_session_moments/session',
            '20261109120150360881_HIL-1919_session_moments/verifier_circle',
            '20261109185924000000_HIL-1927_analytics_signed_moments/analytics',
            '20261110014712983573_HIL-1920_sign_in_moments/identity',
            '20261110014712983573_HIL-1920_sign_in_moments/oauth_provider',
            '20261110014712983573_HIL-1920_sign_in_moments/passkey_credential',
            '20261110021316313226_HIL-1923_notification_moments/notification',
            '20261110021316313226_HIL-1923_notification_moments/notification_delivery',
            '20261110021316313226_HIL-1923_notification_moments/notification_preference',
            '20261110021316313226_HIL-1923_notification_moments/push_subscription',
            '20261110024242374685_HIL-1924_people_moments/access_log',
            '20261110024242374685_HIL-1924_people_moments/account_deletion',
            '20261110024242374685_HIL-1924_people_moments/user',
            '20261110024242374685_HIL-1924_people_moments/user_merge',
            '20261110024242374685_HIL-1924_people_moments/user_photo',
            '20261110024242374685_HIL-1924_people_moments/user_rename',
            '20261110164648748072_HIL-1929_journal_moments/change_log',
            '20261111013234414363_HIL-1921_auth_proof_moments/registration_reservation',
            '20261111013234414363_HIL-1921_auth_proof_moments/step_up',
            '20261111013234414363_HIL-1921_auth_proof_moments/user_verification',
        ];
        $baselineParts = array_map(static function (string $key) use ($catalog): FrameworkMigrationPart {
            [$changeId, $unit] = explode('/', $key);

            return $catalog->part($changeId, $unit);
        }, $baselinePartKeys);
        self::assertSame($this->partRecords($baselineParts), $this->partRecords($origin->parts));
        $changeBase = $root . '/002_framework_change_c12437770c3603bc651bbe5e433c0e256152f4bf69e514b554f40980cf472469';
        $change = $catalog->verifyPair(FsPath::read($changeBase . '.sql'), FsPath::read($changeBase . '_down.sql'));
        self::assertSame(FrameworkMigrationOrigin::CHANGE, $change->kind);
        self::assertSame(['push_subscription'], $change->units);
        self::assertCount(1, $change->parts);
        self::assertSame('20261111062211778865_HIL-1895_push_subscription_revision/push_subscription', $change->parts[0]->key());
        $source = $catalog->part($change->parts[0]->changeId, 'push_subscription');
        self::assertFalse($source->introducesUnit);
        self::assertSame(
            ['20261110021316313226_HIL-1923_notification_moments/push_subscription'],
            $source->requires,
        );
        self::assertSame(
            FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('push_subscription')->upPath)),
            $source->afterStubUpSha256,
        );
        self::assertSame(
            FrameworkMigrationOrigin::bodyHash(FsPath::read($schema->unit('push_subscription')->downPath)),
            $source->afterStubDownSha256,
        );
        self::assertSame([
            $base . '.sql',
            $base . '_down.sql',
            $changeBase . '.sql',
            $changeBase . '_down.sql',
        ], glob($root . '/*.sql'));
        $selectedUnits = array_column($schema->selectedUnits($facade), 'key');
        self::assertContains('change_log', $selectedUnits);
        self::assertSame(
            $this->partRecords($catalog->partsForUnits($selectedUnits)),
            $this->partRecords([...$origin->parts, ...$change->parts]),
        );
        self::assertSame([], $catalog->missingParts($selectedUnits, [$origin, $change]));
    }

    /**
     * @param list<FrameworkMigrationPart> $parts Exact source or origin parts
     * @return list<array{changeId: string, unit: string, upSha256: string, downSha256: string}> Full records sorted by identity
     */
    private function partRecords(array $parts): array
    {
        usort($parts, static fn (FrameworkMigrationPart $left, FrameworkMigrationPart $right): int => $left->key() <=> $right->key());

        return array_map(static fn (FrameworkMigrationPart $part): array => $part->toArray(), $parts);
    }
}

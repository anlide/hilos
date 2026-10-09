<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Migration;
use Hilos\Database\Schema\Schema;
use Hilos\HilosException;

/**
 * An archive from before the person keys can contain references to people no longer there.
 * The migration cleans those rows itself before adding the keys (HIL-1202).
 */
final class PersonForeignKeysMigrationTest extends IntegrationTestCase
{
    private const int MIGRATION_INDEX = 78;
    private const int BEFORE_KEYS = 77;
    private const int MISSING_USER_ID = 900001;
    private const int LIVE_USER_ID = 900002;
    private const string NOW = '2026-10-03 12:00:00';
    private const string MISSING_TOKEN = 'person-key-missing-session';
    private const string IMPERSONATOR_TOKEN = 'person-key-missing-impersonator';
    private const string PENDING_TOKEN = 'person-key-missing-pending';
    private const string BLOCKED_TOKEN = 'person-key-missing-blocked';
    private const string FILE_NAME = 'person-key-orphan-file';

    /** Migration level this shared test database had before this case rewound it. */
    private ?int $latestMigrationIndex = null;

    /** Tables whose whole row belongs to the missing person. */
    private const array PERSON_TABLES = [
        'hilos_notification', 'hilos_notification_preference', 'hilos_push_subscription',
        'hilos_passkey_credential', 'hilos_identity', 'hilos_user_verification',
        'hilos_second_factor', 'hilos_second_factor_backup_code', 'hilos_second_factor_reset',
        'hilos_second_factor_setting', 'hilos_second_factor_trust', 'hilos_step_up',
        'hilos_legal_acceptance', 'hilos_access_log', 'hilos_data_export',
        'hilos_legal_acceptance_export',
    ];

    /**
     * @throws DatabaseException When the migration or fixture statement fails
     */
    public function testOldOrphansAreCleanedBeforeThePersonKeysAreAdded(): void
    {
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $this->latestMigrationIndex = Migration::getCurrentIndex();
        self::assertGreaterThanOrEqual(self::MIGRATION_INDEX, $this->latestMigrationIndex);
        // Earlier integration actions may deliberately leave an undelivered handover receipt.
        // This migration test rewinds past the journal database, so remove only empty receipts.
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        Database::sqlRun("DELETE r FROM {$database}.`hilos_change_log_receipt` r "
            . "WHERE NOT EXISTS (SELECT 1 FROM {$database}.`hilos_change_log` l WHERE l.`receipt_id` = r.`id`)");
        self::assertSame(
            $this->latestMigrationIndex - self::BEFORE_KEYS,
            Migration::migrateDown(self::BEFORE_KEYS),
        );
        Schema::reset();
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Live person')",
            [self::LIVE_USER_ID],
        );

        $notificationId = $this->seedOrphans();

        self::assertSame(1, Migration::migrateUp(self::MIGRATION_INDEX));
        Schema::reset();
        Schema::initialize();

        foreach (self::PERSON_TABLES as $table) {
            Database::sql("SELECT COUNT(*) AS `total` FROM `{$table}` WHERE `user_id` = ?", [self::MISSING_USER_ID]);
            self::assertSame(0, (int)(Database::row()['total'] ?? -1), "{$table} keeps no row about nobody");
        }
        Database::sql('SELECT `id` FROM `hilos_notification_delivery` WHERE `notification_id` = ?', [$notificationId]);
        self::assertNull(Database::row(), 'The orphan notification loses its delivery first');
        Database::sql('SELECT `id` FROM `hilos_passkey_credential` WHERE `credential_id` = ?', ['orphan-passkey']);
        self::assertNull(Database::row(), 'The orphan identity loses its credential first');
        Database::sql('SELECT `id` FROM `hilos_session` WHERE `token` IN (?, ?)', [self::MISSING_TOKEN, self::IMPERSONATOR_TOKEN]);
        self::assertNull(Database::row(), 'Signed-in and impersonating sessions about nobody leave');

        Database::sql('SELECT * FROM `hilos_session` WHERE `token` = ?', [self::PENDING_TOKEN]);
        $pending = Database::row();
        self::assertNotNull($pending);
        foreach ([
            'pending_second_factor_user_id',
            'pending_second_factor_mode',
            'pending_second_factor_until',
            'pending_second_factor_ack',
        ] as $field) {
            self::assertNull($pending[$field], "The {$field} wait field is cleared");
        }
        self::assertSame(0, (int)$pending['pending_second_factor_attempts']);
        Database::sql('SELECT `blocked_user_id`, `blocked_signed_in` FROM `hilos_session` WHERE `token` = ?', [self::BLOCKED_TOKEN]);
        $blocked = Database::row();
        self::assertNotNull($blocked);
        self::assertNull($blocked['blocked_user_id']);
        self::assertSame(0, (int)$blocked['blocked_signed_in']);

        Database::sql('SELECT `owner_user_id` FROM `hilos_file` WHERE `stored_name` = ?', [self::FILE_NAME]);
        $file = Database::row();
        self::assertNotNull($file, 'A file remains without its erased owner');
        self::assertNull($file['owner_user_id']);
        Database::sql('SELECT `id` FROM `hilos_account_deletion` WHERE `user_id` = ?', [self::MISSING_USER_ID]);
        self::assertNotNull(Database::row(), 'The completed-request trace is deliberately soft');

        Database::sql(
            'SELECT COUNT(*) AS `total` FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `CONSTRAINT_NAME` IN (?, ?, ?, ?)',
            ['fk_notification_user', 'fk_session_user', 'fk_session_blocked', 'fk_file_owner'],
        );
        self::assertSame(4, (int)(Database::row()['total'] ?? 0), 'The migration installed its keys');

        Database::sqlRun('DELETE FROM `hilos_session` WHERE `token` IN (?, ?)', [self::PENDING_TOKEN, self::BLOCKED_TOKEN]);
        Database::sqlRun('DELETE FROM `hilos_file` WHERE `stored_name` = ?', [self::FILE_NAME]);
        Database::sqlRun('DELETE FROM `hilos_account_deletion` WHERE `user_id` = ?', [self::MISSING_USER_ID]);
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = ?', [self::LIVE_USER_ID]);
    }

    /**
     * Restores the migration level for cases that follow this one in the shared suite.
     *
     * @throws HilosException When replaying a migration or rebuilding schema fails
     */
    protected function tearDown(): void
    {
        try {
            if ($this->latestMigrationIndex !== null) {
                Migration::migrateUp($this->latestMigrationIndex);
                Schema::reset();
                Schema::initialize();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return int Id of the notification whose delivery must leave before it
     * @throws DatabaseException When an orphan fixture cannot be inserted
     */
    private function seedOrphans(): int
    {
        Database::sqlRun('INSERT INTO `hilos_notification` (`user_id`, `type`, `title`) VALUES (?, ?, ?)',
            [self::MISSING_USER_ID, 'account.test', 'Orphan']);
        Database::sql('SELECT `id` FROM `hilos_notification` WHERE `user_id` = ?', [self::MISSING_USER_ID]);
        $notificationId = (int)(Database::row()['id'] ?? 0);
        Database::sqlRun('INSERT INTO `hilos_notification_delivery` (`notification_id`, `channel`) VALUES (?, ?)',
            [$notificationId, 'email']);
        Database::sqlRun('INSERT INTO `hilos_notification_preference` (`user_id`, `channel`) VALUES (?, ?)',
            [self::MISSING_USER_ID, 'email']);
        Database::sqlRun(
            'INSERT INTO `hilos_push_subscription` (`user_id`, `endpoint`, `p256dh`, `auth`, `endpoint_hash`) '
            . 'VALUES (?, ?, ?, ?, ?)',
            [self::MISSING_USER_ID, 'https://push.example/orphan', 'key', 'secret', str_repeat('a', 64)],
        );
        Database::sqlRun('INSERT INTO `hilos_identity` (`user_id`, `type`, `identifier`) VALUES (?, ?, ?)',
            [self::MISSING_USER_ID, 'passkey', 'orphan-passkey']);
        Database::sql('SELECT `id` FROM `hilos_identity` WHERE `identifier` = ?', ['orphan-passkey']);
        $identityId = (int)(Database::row()['id'] ?? 0);
        Database::sqlRun(
            'INSERT INTO `hilos_passkey_credential` '
            . '(`identity_id`, `user_id`, `credential_id`, `public_key`, `algorithm`, `user_handle`) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [$identityId, self::MISSING_USER_ID, 'orphan-passkey', 'unused', -7, 'handle'],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_user_verification` (`user_id`, `type`, `identifier`, `expires_at`) VALUES (?, ?, ?, ?)',
            [self::MISSING_USER_ID, 'password_reset', 'orphan@example.test', self::NOW],
        );
        Database::sqlRun('INSERT INTO `hilos_second_factor` (`user_id`, `label`) VALUES (?, ?)',
            [self::MISSING_USER_ID, 'Orphan']);
        Database::sqlRun('INSERT INTO `hilos_second_factor_backup_code` (`user_id`) VALUES (?)', [self::MISSING_USER_ID]);
        Database::sqlRun(
            'INSERT INTO `hilos_second_factor_reset` '
            . '(`user_id`, `requested_at`, `effective_at`, `cancel_token_hash`, `notified_at`) VALUES (?, ?, ?, ?, ?)',
            [self::MISSING_USER_ID, self::NOW, self::NOW, str_repeat('b', 64), self::NOW],
        );
        Database::sqlRun('INSERT INTO `hilos_second_factor_setting` (`user_id`) VALUES (?)', [self::MISSING_USER_ID]);
        Database::sqlRun('INSERT INTO `hilos_second_factor_trust` (`session_id`, `user_id`, `trusted_until`) VALUES (?, ?, ?)',
            [900001, self::MISSING_USER_ID, self::NOW]);
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`) '
            . 'VALUES (?, ?, ?, ?)',
            [str_repeat('c', 64), self::MISSING_USER_ID, 'delete_account', self::NOW],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_legal_acceptance` (`user_id`, `document`, `revision_id`, `accepted_at`) VALUES (?, ?, ?, ?)',
            [self::MISSING_USER_ID, 'terms', 'orphan', self::NOW],
        );
        Database::sqlRun('INSERT INTO `hilos_access_log` (`user_id`, `event`, `occurred_at`) VALUES (?, ?, ?)',
            [self::MISSING_USER_ID, 'sign_in', self::NOW]);
        Database::sqlRun('INSERT INTO `hilos_data_export` (`user_id`, `state`, `requested_at`) VALUES (?, ?, ?)',
            [self::MISSING_USER_ID, 'preparing', self::NOW]);
        Database::sqlRun('INSERT INTO `hilos_legal_acceptance_export` (`user_id`, `state`, `requested_at`) VALUES (?, ?, ?)',
            [self::MISSING_USER_ID, 'preparing', self::NOW]);

        Database::sqlRun('INSERT INTO `hilos_session` (`token`, `user_id`) VALUES (?, ?)',
            [self::MISSING_TOKEN, self::MISSING_USER_ID]);
        Database::sqlRun('INSERT INTO `hilos_session` (`token`, `user_id`, `impersonator_user_id`) VALUES (?, ?, ?)',
            [self::IMPERSONATOR_TOKEN, self::LIVE_USER_ID, self::MISSING_USER_ID]);
        Database::sqlRun(
            'INSERT INTO `hilos_session` '
            . '(`token`, `pending_second_factor_user_id`, `pending_second_factor_mode`, `pending_second_factor_until`, '
            . '`pending_second_factor_attempts`, `pending_second_factor_ack`) VALUES (?, ?, ?, ?, ?, ?)',
            [self::PENDING_TOKEN, self::MISSING_USER_ID, 'verify', self::NOW, 2, 'Pending'],
        );
        Database::sqlRun('INSERT INTO `hilos_session` (`token`, `blocked_user_id`, `blocked_signed_in`) VALUES (?, ?, 1)',
            [self::BLOCKED_TOKEN, self::MISSING_USER_ID]);
        Database::sqlRun(
            'INSERT INTO `hilos_file` '
            . '(`stored_name`, `filename`, `mime_type`, `size`, `content_hash`, `owner_user_id`, `visibility`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [self::FILE_NAME, 'orphan.txt', 'text/plain', 1, str_repeat('d', 64), self::MISSING_USER_ID, 'owner'],
        );
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`) VALUES (?, ?, ?)',
            [self::MISSING_USER_ID, self::NOW, self::NOW],
        );

        return $notificationId;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\ClusterContext;
use Hilos\DataExport\DataExportHttp;
use Hilos\DataExport\DataExportNotificationType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Closure;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\DataExport\AbstractDataExportAgent;
use Hilos\DataExport\DataExportState;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\DataExport\DataExportWriter;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\FsException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Legal\LegalCatalogStub;
use PharData;

/** Real queue and archive lifecycle, including a deletion whose sync is still waiting. */
final class DataExportIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const int PHOTO_FILE_ID = 9001;

    private const array EXTRA_TABLES = [
        'hilos_passkey_credential', 'hilos_push_subscription', 'hilos_notification', 'hilos_notification_preference',
        'hilos_file', 'hilos_user_photo',
    ];
    /** Cluster env values the cluster case sets, and tearDown removes. */
    private const array CLUSTER_ENV = ['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'];
    private string $directory;
    private ?FsContext $previousFs;
    private ?SignalRouter $previousRouter;
    private ?HilosNotifier $previousNotify;
    private ?ClusterContext $previousCluster;

    /**
     * @throws HilosException When the fixture cannot be mounted
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun(
            "INSERT INTO hilos_user (id, name) VALUES (7, 'Person'), (8, 'Stranger'), "
            . "(9, 'Old account'), (10, 'Other'), (11, 'Queued')",
        );
        $this->previousRouter = Hilos::$sr;
        $this->previousNotify = Hilos::$notify;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$sr = new SignalRouter();
        Hilos::$notify = new HilosNotifier();
        foreach (array_reverse(self::EXTRA_TABLES) as $table) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
        }
        foreach (self::EXTRA_TABLES as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
        }
        Schema::reset();
        Schema::initialize();
        $this->directory = sys_get_temp_dir() . '/hilos-data-export-build-' . bin2hex(random_bytes(8));
        $this->previousFs = Hilos::$fs;
        Hilos::$fs = new DataExportTestFs($this->directory);
        Hilos::$fs->configure();
        DataExportTestHilos::initBrowser();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
    }

    /**
     * @throws HilosException When fixture tables cannot be dropped
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        foreach (self::CLUSTER_ENV as $key) {
            putenv($key);
        }
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        Hilos::$notify = $this->previousNotify;
        Hilos::$fs = $this->previousFs;
        Hilos::initBrowser();
        Hilos::resetBrowser();
        foreach (glob($this->directory . '/*') as $path) {
            unlink($path);
        }
        if (is_file(ClusterDirectoryMarker::pathIn($this->directory))) {
            unlink(ClusterDirectoryMarker::pathIn($this->directory));
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        foreach (array_reverse(self::EXTRA_TABLES) as $table) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
        }
        Schema::reset();
        parent::tearDown();
    }

    /**
     * JSON sections include this person's complete set and omit both secrets and foreign rows.
     *
     * @throws HilosException When a fixture or archive write fails
     */
    public function testBuildsOnlyPortableFieldsAndProcessesOneRequestPerTick(): void
    {
        self::seedIdentity(7, 'password', 'person@example.test');
        self::seedIdentity(7, 'magic_link', 'person@example.test');
        self::seedIdentity(8, 'magic_link', 'foreign@example.test');
        Database::sqlRun("UPDATE hilos_identity SET secret = 'PASSWORD_SECRET', created_at = '2025-01-01 00:00:00' WHERE user_id = 7");
        self::seedSession('PERSON_SESSION_TOKEN', 7, '2026-01-01 00:00:00', null);
        self::seedSession('FOREIGN_SESSION_TOKEN', 8, '2026-01-01 00:00:00', null);
        self::seedSession('IMPERSONATED_SESSION_TOKEN', 7, '2026-01-01 00:00:00', null, 8);
        Database::sqlRun("UPDATE hilos_session SET ip_address = '192.0.2.7' WHERE token = 'PERSON_SESSION_TOKEN'");
        Database::sqlRun("UPDATE hilos_session SET ip_address = '192.0.2.8' WHERE token <> 'PERSON_SESSION_TOKEN'");
        Database::sqlRun("INSERT INTO hilos_access_log (user_id, event, ip_address, occurred_at) VALUES "
            . "(7, 'new_address', '2001:db8::7', '2026-01-02 00:00:00'), (7, 'sign_in', '192.0.2.7', '2026-01-01 00:00:00'), "
            . "(8, 'sign_in', '192.0.2.8', '2026-01-01 00:00:00')");
        Database::sqlRun("INSERT INTO hilos_second_factor (user_id, label, secret, confirmed_at) "
            . "VALUES (7, 'My app', 'TOTP_SECRET', '2026-01-02 00:00:00')");
        Database::sqlRun("INSERT INTO hilos_second_factor_backup_code (user_id, code) VALUES (7, 'BACKUP_CODE')");
        Database::sqlRun("INSERT INTO hilos_push_subscription (user_id, endpoint, p256dh, auth, endpoint_hash, device_name, user_agent) "
            . "VALUES (7, 'https://SECRET_ENDPOINT', 'P256DH_SECRET', 'AUTH_SECRET', REPEAT('a', 64), 'My device', 'RAW_USER_AGENT')");
        Database::sqlRun("INSERT INTO hilos_notification (user_id, type, severity, title, body) VALUES "
            . "(7, 'notice', 'info', 'My notification', 'My body'), (8, 'notice', 'info', 'Foreign notification', 'Foreign body')");
        Database::sqlRun("INSERT INTO hilos_notification_preference (user_id, channel, enabled) VALUES (7, 'email', 0), (8, 'sms', 0)");
        Database::sqlRun("INSERT INTO hilos_user_merge (user_id, survivor_user_id, merged_at) VALUES "
            . "(7, 8, '2026-01-04 00:00:00'), (9, 7, NULL), (10, 8, '2026-01-04 00:00:00')");
        Database::sqlRun("INSERT INTO hilos_user_rename (user_id, renamed_by_user_id, old_name, new_name, renamed_at) VALUES "
            . "(7, 8, 'Old me', 'New me', '2026-01-03 00:00:00'), (8, 7, 'Foreign old', 'Foreign new', '2026-01-03 00:00:00')");
        Database::sqlRun("INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES "
            . "(7, 'terms', '2026-01-05', '2026-01-05 00:00:00'), "
            . "(7, 'terms', '2026-09-17', '2026-09-20 10:00:00'), "
            . "(7, 'privacy', '2026-09-17', '2026-09-20 10:00:01'), "
            . "(8, 'terms', '2026-09-17', '2026-09-21 00:00:00')");
        $first = Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        Hilos::$db->dataExports->actions->order(8, '2026-01-02 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->onStart();
        $agent->onTick();

        self::assertSame(DataExportState::READY, $first->state);
        self::assertSame(DataExportState::PREPARING, Hilos::$db->dataExports->ofUser(8)?->state);
        self::assertSame(7 * 86400, strtotime($first->expiresAt) - strtotime($first->finishedAt));
        $archive = new PharData($this->directory . '/' . $first->storedName);
        self::assertSame(['id' => 7, 'since' => '2025-01-01T00:00:00Z'], self::section($archive, 'account'));
        self::assertSame([['from' => 'Old me', 'to' => 'New me', 'at' => '2026-01-03T00:00:00Z']], self::section($archive, 'renames'));
        self::assertSame(
            [['account' => 7, 'into' => 8, 'at' => '2026-01-04T00:00:00Z'], ['account' => 9, 'into' => 7, 'at' => null]],
            self::section($archive, 'merges'),
        );
        self::assertCount(2, self::section($archive, 'sign_in_methods'));
        self::assertNull(self::section($archive, 'sign_in_methods')[0]['identifier']);
        self::assertCount(1, self::section($archive, 'sessions'));
        self::assertSame('192.0.2.7', self::section($archive, 'sessions')[0]['address']);
        self::assertSame(
            [
                ['at' => '2026-01-01T00:00:00Z', 'address' => '192.0.2.7', 'event' => 'sign_in'],
                ['at' => '2026-01-02T00:00:00Z', 'address' => '2001:db8::7', 'event' => 'new_address'],
            ],
            self::section($archive, 'access_log'),
        );
        self::assertSame(1, self::section($archive, 'second_factor')['backupCodesLeft']);
        self::assertTrue(self::section($archive, 'second_factor')['connected']);
        self::assertCount(1, self::section($archive, 'notifications'));
        self::assertSame('My notification', self::section($archive, 'notifications')[0]['title']);
        self::assertSame([['channel' => 'email', 'enabled' => false]], self::section($archive, 'notification_preferences'));
        self::assertSame('My device', self::section($archive, 'push_subscriptions')[0]['device']);
        $acceptances = self::section($archive, 'legal_acceptances');
        self::assertCount(3, $acceptances);
        self::assertSame([
            'document' => 'terms',
            'revisionId' => '2026-01-05',
            'acceptedAt' => '2026-01-05T00:00:00Z',
            'inCode' => false,
            'publishedOn' => null,
            'effectiveOn' => null,
            'significance' => null,
            'clauses' => null,
        ], $acceptances[0]);
        self::assertSame([
            'document' => 'terms',
            'revisionId' => '2026-09-17',
            'acceptedAt' => '2026-09-20T10:00:00Z',
            'inCode' => true,
            'publishedOn' => '2026-09-17',
            'effectiveOn' => '2026-09-17',
            'significance' => 'substantial',
        ], array_diff_key($acceptances[1], ['clauses' => true]));
        self::assertCount(6, $acceptances[1]['clauses']);
        self::assertSame([
            'clauseKey' => 'standard.retention',
            'standardStatement' => 'Content is kept for 12 months.',
            'source' => 'deviation',
            'statement' => 'Content is kept for 30 days',
            'direction' => 'looser',
        ], array_diff_key($acceptances[1]['clauses'][1], ['text' => true]));
        self::assertSame(
            trim(file_get_contents(dirname(__DIR__, 2) . '/backend/Legal/Stub/terms/standard.retention.2026-09-17.txt')),
            $acceptances[1]['clauses'][1]['text'],
        );
        self::assertSame([
            'document' => 'privacy',
            'revisionId' => '2026-09-17',
            'acceptedAt' => '2026-09-20T10:00:01Z',
            'inCode' => false,
            'publishedOn' => null,
            'effectiveOn' => null,
            'significance' => null,
            'clauses' => null,
        ], $acceptances[2]);
        self::assertNull(self::section($archive, 'account_deletion'));
        self::assertTrue(isset($archive['README.txt']));
        self::assertStringContainsString('legal_acceptances:', $archive['README.txt']->getContent());
        foreach ($archive as $entry) {
            $text = $entry->getContent();
            foreach (['SECRET', 'BACKUP_CODE', 'SESSION_TOKEN', 'RAW_USER_AGENT', 'Foreign', 'foreign@'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $text);
            }
            foreach (['secret', 'code', 'code_hash', 'endpoint', 'auth', 'user_agent', 'token', 'p256dh', 'renamedByUserId'] as $key) {
                self::assertStringNotContainsString('"' . $key . '"', $text);
            }
        }
        self::assertSame('ready', $agent->published[0]['state']);
        $announcements = $this->announcements();
        self::assertCount(1, $announcements);
        self::assertSame(DataExportNotificationType::READY, $announcements[0]->type);
        self::assertSame(7, $announcements[0]->userId);
        self::assertSame('info', $announcements[0]->severity);
        self::assertSame('Your copy of your data is ready', $announcements[0]->title);
        self::assertSame('It is kept for 7 days. Open Profile › Your data to download it.', $announcements[0]->body);
        self::assertSame(['url' => '/profile/data'], $announcements[0]->data);
        self::assertNull($announcements[0]->channels);
    }

    /** A personal data copy carries the published picture and its set moment. */
    public function testProfilePhotoSectionIncludesItsPicture(): void
    {
        $filesPath = sys_get_temp_dir() . '/hilos-photo-export-files-' . bin2hex(random_bytes(8));
        mkdir($filesPath);
        try {
            file_put_contents($filesPath . '/photo.jpg', 'JPEG');
            Hilos::$fs = new DataExportTestFs($this->directory, $filesPath);
            Hilos::$fs->configure();
            DataExportPhotoTestHilos::initBrowser();
            Database::sqlRun(
                'INSERT INTO `hilos_file` (`id`, `stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
                . '`owner_user_id`, `visibility`, `bound`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [self::PHOTO_FILE_ID, 'photo.jpg', 'photo.jpg', 'image/jpeg', 4, str_repeat('a', 64), 7, 'public', 1],
            );
            Database::sqlRun(
                'INSERT INTO `hilos_user_photo` (`user_id`, `file_id`, `set_at`) VALUES (?, ?, ?)',
                [7, self::PHOTO_FILE_ID, '2026-01-03 00:00:00'],
            );
            $order = Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
            $agent = new DataExportTestAgent();
            $agent->onStart();
            $agent->onTick();

            self::assertSame(DataExportState::READY, $order->state);
            $archive = new PharData($this->directory . '/' . $order->storedName);
            self::assertSame(
                ['file' => 'files/photo.jpg', 'setAt' => '2026-01-03T00:00:00Z'],
                self::section($archive, 'profile_photo'),
            );
            self::assertSame('JPEG', $archive['files/photo.jpg']->getContent());
        } finally {
            unlink($filesPath . '/photo.jpg');
            rmdir($filesPath);
        }
    }

    /** A restored photo row with no original file cannot make the whole copy fail. */
    public function testProfilePhotoSectionIsNullWhenOriginalIsMissing(): void
    {
        $filesPath = sys_get_temp_dir() . '/hilos-photo-export-files-' . bin2hex(random_bytes(8));
        mkdir($filesPath);
        try {
            Hilos::$fs = new DataExportTestFs($this->directory, $filesPath);
            Hilos::$fs->configure();
            DataExportPhotoTestHilos::initBrowser();
            Database::sqlRun(
                'INSERT INTO `hilos_file` (`id`, `stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
                . '`owner_user_id`, `visibility`, `bound`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [self::PHOTO_FILE_ID, 'missing.jpg', 'missing.jpg', 'image/jpeg', 4, str_repeat('a', 64), 7, 'public', 1],
            );
            Database::sqlRun(
                'INSERT INTO `hilos_user_photo` (`user_id`, `file_id`, `set_at`) VALUES (?, ?, ?)',
                [7, self::PHOTO_FILE_ID, '2026-01-03 00:00:00'],
            );
            $order = Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
            $agent = new DataExportTestAgent();
            $agent->onStart();
            $agent->onTick();

            self::assertSame(DataExportState::READY, $order->state);
            $archive = new PharData($this->directory . '/' . $order->storedName);
            self::assertNull(self::section($archive, 'profile_photo'));
        } finally {
            rmdir($filesPath);
        }
    }

    /**
     * Only this person's current browser can download; neither a guest nor an impersonator can.
     *
     * @throws HilosException When seeding or HTTP delivery fails
     */
    public function testHttpDownloadUsesSessionIdentityAndEnforcesExpiry(): void
    {
        self::seedSession('owner', 7, '2026-01-01 00:00:00', null);
        self::seedSession('blocked', null, '2026-01-01 00:00:00', null);
        Hilos::$db->sessions->findByToken('blocked')->actions->holdBlockedNotice(7, true);
        self::seedSession('guest', null, '2026-01-01 00:00:00', null);
        self::seedSession('other', 8, '2026-01-01 00:00:00', null);
        self::seedSession('impersonated', 7, '2026-01-01 00:00:00', null, 8);
        self::seedSession('expired', 7, '2026-01-01 00:00:00', '2026-01-01 00:01:00');
        Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->onStart();
        $agent->onTick();
        foreach ([
            'owner' => 200, 'blocked' => 200, 'guest' => 401, 'other' => 404, 'impersonated' => 403, 'expired' => 401,
        ] as $token => $status) {
            $agent->onSignalHttpRequest(new HttpRequestDTO('request', 'GET', DataExportHttp::DOWNLOAD_PATH, [], $token, null), '', '');
            self::assertSame($status, $agent->httpReply?->status, $token);
            self::assertStringContainsString('no-store', $agent->httpReply->headers['Cache-Control']);
            if ($status === 200) {
                self::assertStringStartsWith('PK', $agent->httpReply->body);
            }
        }
        $agent->onSignalHttpRequest(
            new HttpRequestDTO('request', 'GET', DataExportHttp::DOWNLOAD_PATH, ['token' => 'owner'], null, null), '', '',
        );
        self::assertSame(401, $agent->httpReply?->status, 'A query-string token is never an identity');
        Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00')->actions->finishReady(
            'expired.zip', 10, '2026-01-01 00:01:00', '2026-01-02 00:01:00',
        );
        file_put_contents($this->directory . '/expired.zip', 'expired bytes');
        $agent->onSignalHttpRequest(new HttpRequestDTO('request', 'GET', DataExportHttp::DOWNLOAD_PATH, [], 'owner', null), '', '');
        self::assertSame(404, $agent->httpReply?->status, 'An expired copy is not served before the hourly sweep');
    }

    /**
     * Without nginx the daemon's own body reaches only a browser this node holds; the journal names
     * the node and the env value the operator has to set.
     *
     * @throws HilosException When seeding or HTTP delivery fails
     */
    public function testHttpDownloadToABrowserOnAnotherNodeNeedsNginx(): void
    {
        self::seedSession('owner', 7, '2026-01-01 00:00:00', null);
        Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->onStart();
        $agent->onTick();
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$cluster = new ClusterContext();

        $agent->onSignalHttpRequest(new HttpRequestDTO('request', 'GET', DataExportHttp::DOWNLOAD_PATH, [], 'owner', 'node-b'), '', '');
        self::assertSame(500, $agent->httpReply?->status);
        self::assertSame([
            "Data export is not sent by the daemon: the browser's connection is on node node-b"
            . " and the daemon's own body does not travel between nodes; set HILOS_DATA_EXPORT_XACCEL_LOCATION",
        ], $agent->errors);

        $agent->onSignalHttpRequest(new HttpRequestDTO('request', 'GET', DataExportHttp::DOWNLOAD_PATH, [], 'owner', 'node-a'), '', '');
        self::assertSame(200, $agent->httpReply?->status);
        self::assertStringStartsWith('PK', $agent->httpReply->body);
    }

    /**
     * A store-only project has no push table, which must not prevent its export.
     *
     * @throws HilosException When a fixture or archive write fails
     */
    public function testBuildsWhenPushStorageIsNotActivated(): void
    {
        Database::sqlRun('DROP TABLE hilos_push_subscription');
        Schema::reset();
        Schema::initialize();
        $export = Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->onStart();
        $agent->onTick();
        self::assertSame(DataExportState::READY, $export->state);
        $archive = new PharData($this->directory . '/' . $export->storedName);
        self::assertSame([], self::section($archive, 'push_subscriptions'));
        self::assertSame([], self::section($archive, 'legal_acceptances'));
    }

    /**
     * Startup discards missing/expired copies and orphan files, retaining live ready and queued rows.
     *
     * The cluster directory marker is no orphan: it is the framework's file, and a sweep that took it
     * would split a cluster on the next restart of a node (HIL-1242). `glob('/*')` does not list a
     * dot file, so the marker is asserted on its own.
     *
     * @throws HilosException When fixture or reconciliation fails
     */
    public function testStartupCleansOrphansMissingFilesAndExpiredCopies(): void
    {
        mkdir($this->directory);
        $future = date('Y-m-d H:i:s', time() + 86400);
        foreach ([7 => 'kept.zip', 8 => 'missing.zip', 9 => 'expired.zip'] as $userId => $name) {
            $row = Hilos::$db->dataExports->actions->order($userId, '2026-01-01 00:00:00');
            $row->actions->finishReady($name, 4, '2026-01-01 00:01:00', $userId === 9 ? '2026-01-02 00:00:00' : $future);
        }
        Hilos::$db->dataExports->actions->order(10, '2026-01-01 00:00:00')->actions->finishFailed(
            '2026-01-01 00:01:00', '2026-01-02 00:00:00',
        );
        Hilos::$db->dataExports->actions->order(11, '2026-01-01 00:00:00');
        foreach (['kept.zip', 'expired.zip', 'abandoned.building.zip', 'orphan.zip', ClusterDirectoryMarker::FILE_NAME] as $name) {
            file_put_contents($this->directory . '/' . $name, 'file');
        }
        $agent = new DataExportTestAgent();
        $agent->onStart();
        self::assertSame(['kept.zip'], array_map('basename', glob($this->directory . '/*')));
        self::assertFileExists(ClusterDirectoryMarker::pathIn($this->directory));
        foreach ([8, 9, 10] as $userId) {
            self::assertNull(Hilos::$db->dataExports->ofUser($userId));
        }
        self::assertSame(DataExportState::PREPARING, Hilos::$db->dataExports->ofUser(11)?->state);
        self::assertSame([null, null, null], $agent->published);
        $agent->onTick();
        self::assertSame(DataExportState::READY, Hilos::$db->dataExports->ofUser(11)?->state);
    }

    /**
     * Erasure commits during the project export, while the worker cannot consume its sync frame.
     *
     * @throws HilosException When the fixture or build fails
     */
    public function testErasureDuringBuildDiscardsEverythingWithoutPublication(): void
    {
        Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->duringExport = static function (): void {
            Database::sqlRun("INSERT INTO hilos_account_deletion (user_id, requested_at, effective_at, completed_at) "
                . "VALUES (7, '2026-01-01 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:01:00')");
        };
        $agent->onStart();
        $agent->onTick();
        self::assertNull(Hilos::$db->dataExports->ofUser(7));
        self::assertSame([], glob($this->directory . '/*'));
        self::assertSame([], $agent->published);
        self::assertSame([], $this->announcements());
    }

    /**
     * A completion for a replaced request cannot publish the old archive.
     *
     * @throws HilosException When the fixture or build fails
     */
    public function testReplacedRequestCannotPublishLateCompletion(): void
    {
        Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->duringExport = static function (): void {
            Hilos::$db->dataExports->actions->order(7, '2026-01-02 00:00:00');
        };
        $agent->onStart();
        $agent->onTick();
        self::assertSame('2026-01-02 00:00:00', Hilos::$db->dataExports->ofUser(7)?->requestedAt);
        self::assertSame(DataExportState::PREPARING, Hilos::$db->dataExports->ofUser(7)?->state);
        self::assertSame([], glob($this->directory . '/*'));
        self::assertSame([], $agent->published);
        self::assertSame([], $this->announcements());
    }

    /**
     * A failed project contribution discards partial bytes, records failure and waits for a new order.
     *
     * @throws HilosException When the fixture or build fails
     */
    public function testFailureDoesNotRetryItself(): void
    {
        $export = Hilos::$db->dataExports->actions->order(7, '2026-01-01 00:00:00');
        $agent = new DataExportTestAgent();
        $agent->duringExport = static function (): void { throw new FsException('Missing project attachment'); };
        $agent->onStart();
        $agent->onTick();
        self::assertSame(DataExportState::FAILED, $export->state);
        self::assertNull($export->storedName);
        self::assertSame([], glob($this->directory . '/*'));
        $agent->onTick();
        self::assertCount(1, $agent->published);
        self::assertSame('failed', $agent->published[0]['state']);
        $announcements = $this->announcements();
        self::assertCount(1, $announcements);
        self::assertSame(DataExportNotificationType::FAILED, $announcements[0]->type);
        self::assertSame(7, $announcements[0]->userId);
        self::assertSame('warning', $announcements[0]->severity);
        self::assertSame('We could not prepare your copy of your data', $announcements[0]->title);
        self::assertSame('Open Profile › Your data to try again.', $announcements[0]->body);
        self::assertSame(['url' => '/profile/data'], $announcements[0]->data);
        self::assertNull($announcements[0]->channels);
    }

    /** @return list<NotificationEmitSignalData> Completion notices queued for the notification owner */
    private function announcements(): array
    {
        $notices = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_NOTIFICATION_EMIT) {
                continue;
            }
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(NotificationEmitSignalData::class, $signal->data->data);
            $notices[] = $signal->data->data;
        }

        return $notices;
    }

    /**
     * @param PharData $archive Prepared ZIP
     * @param string $name Section name
     * @return mixed Decoded JSON for assertions
     */
    private static function section(PharData $archive, string $name): mixed
    {
        return json_decode($archive[$name . '.json']->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class DataExportTestFs extends FsContext
{
    /**
     * @param string $path Private test directory
     * @param ?string $filesPath Optional published-files directory
     */
    public function __construct(private readonly string $path, private readonly ?string $filesPath = null) { }

    /** Registers a fresh directory for each test. */
    public function configure(): void
    {
        $this->registerDirectory(self::DATA_EXPORT, $this->path, DirectoryScope::CLUSTER);
        if ($this->filesPath !== null) {
            $this->registerDirectory(self::FILES, $this->filesPath, DirectoryScope::CLUSTER);
        }
    }
}

class DataExportTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::NOTIFICATIONS];
    protected const ?string LEGAL_CATALOG = LegalCatalogStub::class;

    /** @return HilosDbContext Framework collections used by the fixture */
    protected static function createDb(): HilosDbContext
    {
        return new HilosSessionTestDbContext();
    }
}

final class DataExportPhotoTestHilos extends DataExportTestHilos
{
    protected const array FEATURES = [HilosFeature::NOTIFICATIONS, HilosFeature::PROFILE_PHOTO];
}

final class DataExportTestAgent extends AbstractDataExportAgent
{
    /** @var list<?array<string, mixed>> State publications captured at the group boundary */
    public array $published = [];
    public ?Closure $duringExport = null;
    public ?HttpReplyDTO $httpReply = null;
    /** @var list<string> Error lines */
    public array $errors = [];

    /** @param HttpReplyDTO $reply Captured HTTP response */
    public function replyToHttpRequest(HttpReplyDTO $reply): void
    {
        $this->httpReply = $reply;
    }

    /** @param string $message Message the export agent logged */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * @param int $userId Export owner
     * @param DataExportWriter $writer Real archive writer
     * @throws HilosException When the test simulates a project failure
     */
    protected function applyAccountExport(int $userId, DataExportWriter $writer): void
    {
        $writer->section('project', ['id' => $userId]);
        ($this->duringExport)?->__invoke();
    }

    /**
     * @param int $userId Export owner
     * @throws HilosException When the published state cannot be read
     */
    protected function publishState(int $userId): void
    {
        $this->published[] = DataExportStateProjector::stateFor($userId)->dataExport;
    }
}

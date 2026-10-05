<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalLoader;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\SecretPayloadMask;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;

/**
 * Analytics keeps no password or code of an action, on the real chat topology (HIL-1187).
 *
 * The collector finds what to mask through the chat's own router, so these cases run the
 * declarations the project really ships: a sign-in written by the master, a password change as
 * the agent receives it - the action inside the envelope's `data` - and a name no page or agent
 * routes. The last case plays the cleanup migration over rows written the old way.
 */
final class AnalyticsSecretFieldsTest extends IntegrationTestCase
{
    private const string MIGRATION_FILE = '/backend/Database/Migration/Schema/066_mask_hilos_analytics_payload_secrets.sql';

    private const string AGENT_TYPE = 'hil_1187_agent';

    private const int WORKER_INDEX = 1187;

    private const string OLD_PASSWORD = 'hil-1187-old-pw';

    private const string OLD_STEP_UP_PASSWORD = 'hil-1187-old-step';

    private string $acceptKey;

    private int $journalFiles = 0;

    private AnalyticsCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::initSignalRouter(new ChatSignalRouter());
        $this->collector = new AnalyticsCollector();
        $this->acceptKey = 'hil-1187-' . RandomHelper::hex(8);
        $this->collector->openWsConnection($this->acceptKey, null);
    }

    /**
     * @throws DatabaseException When the rows of this case cannot be removed
     */
    protected function tearDown(): void
    {
        Database::sql('DELETE FROM `hilos_analytics_ws_connection` WHERE `accept_key` = ?', [$this->acceptKey]);
        Database::sql('DELETE FROM `hilos_analytics_worker_session` WHERE `worker_index` = ?', [self::WORKER_INDEX]);
        Database::sql('DELETE FROM `hilos_analytics_journal_file` WHERE `file_name` LIKE ?', [$this->acceptKey . '%']);
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * @throws DatabaseException When the recorded payload cannot be read
     * @throws JsonException When the recorded payload is not JSON
     */
    public function testSignInIsRecordedWithThePasswordMasked(): void
    {
        $userActionKey = $this->collector->logUserAction($this->acceptKey, HilosSignalConstants::HILOS_LOGIN, [
            'email' => 'hil-1187@example.test',
            'password' => 'hil-1187-pw',
        ]);
        $this->assertNotNull($userActionKey);
        $this->loadJournal($this->journalLines());

        $json = $this->userActionPayload($userActionKey);

        $this->assertNotNull($json);
        $this->assertStringNotContainsString('hil-1187-pw', $json);
        $this->assertSame(
            ['email' => 'hil-1187@example.test', 'password' => SecretPayloadMask::MASK],
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The worker's half masks before the journal: the file on the node's disk is storage too, so
     * the secret is gone from the journal line already, and from the row the writer loads it into.
     *
     * @throws HilosException When the journal cannot be loaded or the recorded payload read
     * @throws JsonException When the recorded payload is not JSON
     */
    public function testAgentReactionIsRecordedWithTheCodeAndPasswordMaskedInsideData(): void
    {
        $agentSessionId = $this->openAgentSession();
        // The add-password confirm carries both a code and a password; the password change
        // stopped carrying its code when the proof moved into the session (HIL-1182).
        $envelope = new WebSocketActionSignalDTO($this->acceptKey, HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM, [
            'code' => '123456',
            'newPassword' => 'hil-1187-new',
        ]);

        $this->collector->logAgentUserAction(
            self::AGENT_TYPE,
            null,
            null,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
            $envelope->toArray(),
        );
        $lines = $this->journalLines();
        $journal = implode("\n", $lines);
        $this->assertStringNotContainsString('123456', $journal);
        $this->assertStringNotContainsString('hil-1187-new', $journal);
        $this->loadJournal($lines);

        Database::sql(
            'SELECT p.`payload_json` FROM `hilos_analytics_agent_user_action` a
             JOIN `hilos_analytics_payload_json` p ON p.`id` = a.`payload_json_id`
             WHERE a.`agent_session_id` = ?',
            [$agentSessionId],
        );
        $json = (string)Database::field('payload_json');

        $this->assertStringNotContainsString('123456', $json);
        $this->assertStringNotContainsString('hil-1187-new', $json);
        $this->assertSame(
            ['code' => SecretPayloadMask::MASK, 'newPassword' => SecretPayloadMask::MASK],
            json_decode($json, true, flags: JSON_THROW_ON_ERROR)['data'],
        );
    }

    /**
     * @throws DatabaseException When the recorded row cannot be read
     * @throws HilosException When the journal cannot be loaded
     */
    public function testActionNoPageOrAgentRoutesKeepsItsNameAndLosesItsPayload(): void
    {
        $userActionKey = $this->collector->logUserAction($this->acceptKey, 'hil_1187_unknown_action', ['x' => 'y']);
        $this->assertNotNull($userActionKey);
        $this->loadJournal($this->journalLines());

        Database::sql(
            'SELECT n.`name`, ua.`payload_json_id` FROM `hilos_analytics_user_action` ua
             JOIN `hilos_analytics_action_name` n ON n.`id` = ua.`action_name_id`
             WHERE ua.`action_key` = UNHEX(?)',
            [$userActionKey],
        );
        $row = Database::row();

        $this->assertSame('hil_1187_unknown_action', $row['name'] ?? null);
        $this->assertNull($row['payload_json_id'] ?? null);
    }

    /**
     * @throws HilosException When a row cannot be written or read, the journal loaded, or the migration fails
     * @throws JsonException When a payload is not JSON
     */
    public function testMigrationMasksWhatWasWrittenBeforeAndChangesNothingTheSecondTime(): void
    {
        $agentSessionId = $this->openAgentSession();
        $signInId = $this->insertOldPayload(
            '{"email": "old@example.test", "password": "' . self::OLD_PASSWORD . '"}',
        );
        $stepUpId = $this->insertOldPayload(
            '{"acceptKey": "' . $this->acceptKey . '", "action": "hilos_step_up_confirm", "data": {"operation": "profile_rename",'
                . ' "code": "", "password": "' . self::OLD_STEP_UP_PASSWORD . '", "passkey": null}}',
        );
        Database::sql(
            'INSERT INTO `hilos_analytics_user_action` (`ws_connection_id`, `action_name_id`, `payload_json_id`, `created_ts`)
             SELECT `id`, ?, ?, 0 FROM `hilos_analytics_ws_connection` WHERE `accept_key` = ?',
            [$this->namedDictionaryId('hilos_analytics_action_name', HilosSignalConstants::HILOS_LOGIN), $signInId, $this->acceptKey],
        );
        Database::sql(
            'INSERT INTO `hilos_analytics_agent_user_action` (`agent_session_id`, `signal_name_id`, `payload_json_id`, `created_ts`)
             VALUES (?, ?, ?, 0)',
            [$agentSessionId, $this->namedDictionaryId('hilos_analytics_signal_name', HilosSignalConstants::HILOS_STEP_UP_CONFIRM), $stepUpId],
        );

        $this->runMigration();

        $signIn = $this->payloadRow($signInId);
        $this->assertSame(
            ['email' => 'old@example.test', 'password' => SecretPayloadMask::MASK],
            json_decode($signIn['payload_json'], true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(sha1('HIL-1187 masked ' . $signInId), $signIn['hash']);

        $stepUp = $this->payloadRow($stepUpId);
        $this->assertStringNotContainsString(self::OLD_STEP_UP_PASSWORD, $stepUp['payload_json']);
        $this->assertSame(
            ['operation' => 'profile_rename', 'code' => '', 'password' => SecretPayloadMask::MASK, 'passkey' => null],
            json_decode($stepUp['payload_json'], true, flags: JSON_THROW_ON_ERROR)['data'],
        );
        $this->assertSame(sha1('HIL-1187 masked ' . $stepUpId), $stepUp['hash']);

        $this->runMigration();

        $this->assertSame($signIn, $this->payloadRow($signInId));
        $this->assertSame($stepUp, $this->payloadRow($stepUpId));
    }

    /**
     * Opens a worker and an agent session the way a worker does, and loads them through the journal.
     *
     * @return int Agent session id the writer gave the agent of this case
     * @throws HilosException When the journal cannot be loaded or the session read back
     */
    private function openAgentSession(): int
    {
        $this->collector->openWorkerSession(self::WORKER_INDEX, false);
        $this->collector->openAgentSession(self::AGENT_TYPE, null);
        $this->loadJournal($this->journalLines());

        Database::sql(
            'SELECT a.`id` FROM `hilos_analytics_agent_session` a
             JOIN `hilos_analytics_worker_session` w ON w.`id` = a.`worker_session_id`
             WHERE w.`worker_index` = ? AND a.`agent_type` = ?',
            [self::WORKER_INDEX, self::AGENT_TYPE],
        );
        $agentSessionId = Database::field('id');
        $this->assertNotNull($agentSessionId);

        return (int)$agentSessionId;
    }

    /**
     * Sends what the collector gathered and takes the batches it queued for the journal.
     *
     * @return list<string> Lines of every queued batch, in order
     */
    private function journalLines(): array
    {
        $this->collector->flush();

        $lines = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $batch = $signal->data instanceof AgentSignalData ? $signal->data->data : null;
            if ($batch instanceof AnalyticsJournalAppendSignalData) {
                array_push($lines, ...$batch->lines);
            }
        }

        return $lines;
    }

    /**
     * Loads journal lines into the chat database the way the writer loads a file.
     *
     * @param list<string> $lines Lines of the file
     * @throws HilosException When the load fails
     */
    private function loadJournal(array $lines): void
    {
        $this->journalFiles++;
        $outcome = new AnalyticsJournalLoader(new AnalyticsStore())->load('', $this->acceptKey . '-' . $this->journalFiles, $lines);
        $this->assertSame([], $outcome->skipped);
    }

    /**
     * @param string $userActionKey User action key
     * @return ?string JSON the user action points at, or null when it points at none
     * @throws DatabaseException When the query fails
     */
    private function userActionPayload(string $userActionKey): ?string
    {
        Database::sql(
            'SELECT p.`payload_json` FROM `hilos_analytics_user_action` ua
             JOIN `hilos_analytics_payload_json` p ON p.`id` = ua.`payload_json_id`
             WHERE ua.`action_key` = UNHEX(?)',
            [$userActionKey],
        );
        $json = Database::field('payload_json');

        return $json === null ? null : (string)$json;
    }

    /**
     * Returns the row of a name in a name dictionary, writing it first when the name is new.
     *
     * @param string $table Name dictionary table
     * @param string $name Name to look up
     * @return int Dictionary row id
     * @throws DatabaseException When the insert or the lookup fails
     */
    private function namedDictionaryId(string $table, string $name): int
    {
        Database::sql("INSERT IGNORE INTO `{$table}` (`name`, `created_ts`) VALUES (?, 0)", [$name]);
        Database::sql("SELECT `id` FROM `{$table}` WHERE `name` = ?", [$name]);

        return (int)Database::field('id');
    }

    /**
     * Writes a payload row the way the collector did before it masked anything.
     *
     * @param string $json Payload JSON with its secret in the clear
     * @return int Payload row id
     * @throws DatabaseException When the insert fails
     */
    private function insertOldPayload(string $json): int
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_payload_json` (`sha1_hash`, `payload_json`, `created_ts`) VALUES (UNHEX(?), ?, 0)',
            [sha1($json), $json],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $id Payload row id
     * @return array{payload_json: string, hash: string} Stored JSON and its fingerprint in lowercase hex
     * @throws DatabaseException When the query fails
     */
    private function payloadRow(int $id): array
    {
        Database::sql(
            'SELECT `payload_json`, LOWER(HEX(`sha1_hash`)) AS `hash` FROM `hilos_analytics_payload_json` WHERE `id` = ?',
            [$id],
        );
        $row = Database::row();
        $this->assertNotNull($row);

        return ['payload_json' => (string)$row['payload_json'], 'hash' => (string)$row['hash']];
    }

    /**
     * Runs the cleanup migration file statement by statement, as the migration runner splits it.
     *
     * @throws DatabaseException When a statement fails
     */
    private function runMigration(): void
    {
        $statement = '';
        foreach (explode("\n", (string)file_get_contents(dirname(__DIR__, 2) . self::MIGRATION_FILE)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }

            $statement .= $line . "\n";
            if (str_ends_with($line, ';')) {
                Database::sql(rtrim(trim($statement), ';'));
                $statement = '';
            }
        }
    }
}

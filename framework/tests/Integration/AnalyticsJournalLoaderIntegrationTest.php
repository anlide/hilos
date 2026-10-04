<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsApiRequest;
use Hilos\Core\Analytics\AnalyticsJournalLoader;
use Hilos\Core\Analytics\AnalyticsJournalLoadOutcome;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsJournalSkip;
use Hilos\Core\Analytics\AnalyticsLossReason;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * The writer's half of the analytics journal: a ready file loaded into the analytics tables (HIL-1154).
 *
 * Every case builds its lines with the same builders the sources use, so the wire the loader
 * reads is the wire they write. The schema is the shipped stub, built empty for each case.
 */
final class AnalyticsJournalLoaderIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const string NODE = '';

    private const string WORKER_KEY = '0123456789abcdef0123456789abcdef';

    private const string AGENT_KEY = 'fedcba9876543210fedcba9876543210';

    private const string AGENT_TYPE = 'hil_1154_agent';

    private const int WORKER_INDEX = 4;

    private const int STARTED_TS = 1_700_000_000_000;

    private const string ACTION_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string REQUEST_KEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string ACCEPT_KEY = 'hil-1154-accept-key';

    private const string TOKEN = 'hil-1154-token';

    private const string ROTATED_TOKEN = 'hil-1154-rotated';

    private int $fileNumber = 0;

    /**
     * @throws HilosException When a load fails
     */
    public function testEveryRecordTypeLandsInItsTableWithTheMomentOfTheSource(): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection` (`accept_key`, `opened_ts`) VALUES (?, ?)',
            [self::ACCEPT_KEY, self::STARTED_TS],
        );

        $outcome = $this->load([
            AnalyticsJournalRecord::journal(self::NODE, self::STARTED_TS),
            ...$this->sessions(),
            AnalyticsJournalRecord::agentUserAction(self::AGENT_KEY, null, 'hil_1154_action', ['field' => 'one'], self::STARTED_TS + 1),
            AnalyticsJournalRecord::agentSystemSignal(self::AGENT_KEY, 'hil_1154_system', null, self::STARTED_TS + 2),
            AnalyticsJournalRecord::agentCronSignal(self::AGENT_KEY, 'hil_1154_cron', ['tick' => 1], self::STARTED_TS + 3),
            AnalyticsJournalRecord::workerSystemSignal(self::WORKER_KEY, 'hil_1154_worker', null, self::STARTED_TS + 4),
            AnalyticsJournalRecord::wsConnectionAttach(self::ACCEPT_KEY, self::TOKEN, 'UA/1.0', 'en', self::STARTED_TS + 5),
            AnalyticsJournalRecord::browserSessionIdentity(self::TOKEN, 'user_id', '42', self::STARTED_TS + 6),
            AnalyticsJournalRecord::browserSessionRename(self::TOKEN, self::ROTATED_TOKEN, self::STARTED_TS + 7),
            AnalyticsJournalRecord::agentSessionStop(self::AGENT_KEY, self::STARTED_TS + 8),
            AnalyticsJournalRecord::workerSessionStop(self::WORKER_KEY, self::STARTED_TS + 9),
        ]);

        $this->assertFalse($outcome->alreadyLoaded);
        $this->assertSame(11, $outcome->recordCount);
        $this->assertSame([], $outcome->skipped);

        $this->assertSame(
            [[self::WORKER_KEY, (string)self::WORKER_INDEX, '1', (string)self::STARTED_TS, (string)(self::STARTED_TS + 9)]],
            $this->rows('SELECT LOWER(HEX(`session_key`)), `worker_index`, `is_monopolistic`, `started_ts`, `stopped_ts`
                FROM `hilos_analytics_worker_session`'),
        );
        $this->assertSame(
            [[self::AGENT_KEY, self::AGENT_TYPE, '7', (string)self::STARTED_TS, (string)(self::STARTED_TS + 8)]],
            $this->rows('SELECT LOWER(HEX(`session_key`)), `agent_type`, `agent_index`, `started_ts`, `stopped_ts`
                FROM `hilos_analytics_agent_session`'),
        );
        $action = $this->rows('SELECT n.`name`, p.`payload_json`, f.`created_ts` FROM `hilos_analytics_agent_user_action` f
            JOIN `hilos_analytics_signal_name` n ON n.`id` = f.`signal_name_id`
            JOIN `hilos_analytics_payload_json` p ON p.`id` = f.`payload_json_id`');
        $this->assertCount(1, $action);
        $this->assertSame('hil_1154_action', $action[0][0]);
        $this->assertSame(['field' => 'one'], json_decode((string)$action[0][1], true));
        $this->assertSame((string)(self::STARTED_TS + 1), $action[0][2]);
        $this->assertSame(
            [['hil_1154_system', (string)(self::STARTED_TS + 2)]],
            $this->rows('SELECT n.`name`, f.`created_ts` FROM `hilos_analytics_agent_system_signal` f
                JOIN `hilos_analytics_signal_name` n ON n.`id` = f.`signal_name_id`'),
        );
        $this->assertSame(
            [['hil_1154_cron', (string)(self::STARTED_TS + 3)]],
            $this->rows('SELECT n.`name`, f.`created_ts` FROM `hilos_analytics_agent_cron_signal` f
                JOIN `hilos_analytics_cron_name` n ON n.`id` = f.`cron_name_id`'),
        );
        $this->assertSame(
            [['hil_1154_worker', (string)(self::STARTED_TS + 4)]],
            $this->rows('SELECT n.`name`, f.`created_ts` FROM `hilos_analytics_worker_system_signal` f
                JOIN `hilos_analytics_signal_name` n ON n.`id` = f.`signal_name_id`'),
        );
        $this->assertSame(
            [[self::ROTATED_TOKEN, 'user_id', '42', 'UA/1.0', (string)(self::STARTED_TS + 5), (string)(self::STARTED_TS + 7)]],
            $this->rows('SELECT b.`session_token`, b.`user_identity_type`, b.`user_identity_value`, u.`value`,
                    b.`first_seen_ts`, b.`last_seen_ts`
                FROM `hilos_analytics_browser_session` b
                JOIN `hilos_analytics_user_agent` u ON u.`id` = b.`current_user_agent_id`'),
        );
        $this->assertSame(
            [[self::ROTATED_TOKEN]],
            $this->rows('SELECT b.`session_token` FROM `hilos_analytics_ws_connection` c
                JOIN `hilos_analytics_browser_session` b ON b.`id` = c.`browser_session_id`'),
        );
        $this->assertSame([['', $this->lastFileName(), '11']], $this->rows(
            'SELECT `node_id`, `file_name`, `record_count` FROM `hilos_analytics_journal_file`',
        ));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testRepeatedDescriptionsAreOneSessionAndAStopIsStampedOnce(): void
    {
        $loader = $this->loader();
        $this->load([
            ...$this->sessions(),
            AnalyticsJournalRecord::agentSessionStop(self::AGENT_KEY, self::STARTED_TS + 10),
        ], $loader);
        $this->load([
            ...$this->sessions(),
            ...$this->sessions(),
            AnalyticsJournalRecord::agentSessionStop(self::AGENT_KEY, self::STARTED_TS + 20),
        ], $loader);

        // A fresh writer knows nothing in memory, and still finds the rows by their keys.
        $this->load([
            ...$this->sessions(),
            AnalyticsJournalRecord::agentSessionStop(self::AGENT_KEY, self::STARTED_TS + 30),
        ]);

        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_worker_session`'));
        $this->assertSame(
            [[(string)(self::STARTED_TS + 10)]],
            $this->rows('SELECT `stopped_ts` FROM `hilos_analytics_agent_session`'),
        );
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testAFileLoadedOnceIsOnlyConfirmedTheSecondTime(): void
    {
        $lines = $this->lines([
            ...$this->sessions(),
            AnalyticsJournalRecord::agentSystemSignal(self::AGENT_KEY, 'hil_1154_system', null, self::STARTED_TS),
        ]);

        $first = $this->loader()->load(self::NODE, 'hil-1154.jsonl', $lines);
        $second = $this->loader()->load(self::NODE, 'hil-1154.jsonl', $lines);

        $this->assertFalse($first->alreadyLoaded);
        $this->assertTrue($second->alreadyLoaded);
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_agent_system_signal`'));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_journal_file`'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testLossAndLoaderSkipsBelongToTheFileAndLoadOnlyOnce(): void
    {
        $lines = $this->lines([
            AnalyticsJournalRecord::journal(self::NODE, self::STARTED_TS),
            AnalyticsJournalRecord::loss(AnalyticsLossReason::RESTORE, 4, self::STARTED_TS + 1, self::STARTED_TS + 2),
            [AnalyticsJournalRecord::KEY_TYPE => 'unrecognized'],
            [AnalyticsJournalRecord::KEY_TYPE => AnalyticsJournalRecord::TYPE_LOSS,
                AnalyticsJournalRecord::KEY_REASON => 'unexpected', AnalyticsJournalRecord::KEY_EVENTS => 2,
                AnalyticsJournalRecord::KEY_FROM_TS => self::STARTED_TS, AnalyticsJournalRecord::KEY_TO_TS => self::STARTED_TS],
            [AnalyticsJournalRecord::KEY_TYPE => AnalyticsJournalRecord::TYPE_LOSS,
                AnalyticsJournalRecord::KEY_REASON => AnalyticsLossReason::RESTORE->value, AnalyticsJournalRecord::KEY_EVENTS => 0,
                AnalyticsJournalRecord::KEY_FROM_TS => self::STARTED_TS, AnalyticsJournalRecord::KEY_TO_TS => self::STARTED_TS],
            AnalyticsJournalRecord::journalEnd(1, self::STARTED_TS + 10),
        ]);
        $loader = $this->loader();
        $first = $loader->load('node-a', 'loss.jsonl', $lines, 3);
        $second = $loader->load('node-a', 'loss.jsonl', $lines, 3);

        $this->assertFalse($first->alreadyLoaded);
        $this->assertTrue($second->alreadyLoaded);
        $this->assertSame(4, $first->recordCount);
        $this->assertSame([
            AnalyticsJournalSkip::UNKNOWN_TYPE->value => 1,
            AnalyticsJournalSkip::MALFORMED->value => 2,
        ], $first->skipped);
        $this->assertSame([
            ['node-a', 'restore', '4', (string)(self::STARTED_TS + 1), (string)(self::STARTED_TS + 2)],
            ['node-a', 'unknown_type', '1', (string)self::STARTED_TS, (string)(self::STARTED_TS + 10)],
            ['node-a', 'malformed', '2', (string)self::STARTED_TS, (string)(self::STARTED_TS + 10)],
            ['node-a', 'line_too_long', '3', (string)self::STARTED_TS, (string)(self::STARTED_TS + 10)],
        ], $this->rows('SELECT `node_id`, `reason`, `event_count`, `from_ts`, `to_ts` FROM `hilos_analytics_loss` ORDER BY `id`'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testSkipPeriodFallsBackToOpeningWhenThereIsNoEnd(): void
    {
        $this->load([
            AnalyticsJournalRecord::journal(self::NODE, self::STARTED_TS),
            ['t' => 'unrecognized'],
        ]);

        $this->assertSame([
            ['unknown_type', (string)self::STARTED_TS, (string)self::STARTED_TS],
        ], $this->rows('SELECT `reason`, `from_ts`, `to_ts` FROM `hilos_analytics_loss`'));
    }

    /**
     * The failure comes at the very end - the last fact table is gone when the facts are
     * written - after sessions and dictionary rows were inserted inside the transaction. Those
     * rows are rolled back, and a writer that kept their numbers in memory would hand the
     * second load numbers of rows that do not exist.
     *
     * @throws HilosException When the second load fails
     */
    public function testAFailedLoadWritesNothingAndTheRetryUsesNoNumberOfTheRolledBackRows(): void
    {
        $loader = $this->loader();
        $lines = $this->lines([
            ...$this->sessions(),
            AnalyticsJournalRecord::loss(AnalyticsLossReason::RESTORE, 2, self::STARTED_TS, self::STARTED_TS),
            AnalyticsJournalRecord::agentSystemSignal(self::AGENT_KEY, 'hil_1154_system', null, self::STARTED_TS),
            AnalyticsJournalRecord::agentCronSignal(self::AGENT_KEY, 'hil_1154_cron', null, self::STARTED_TS),
        ]);

        Database::sql('RENAME TABLE `hilos_analytics_agent_cron_signal` TO `hilos_analytics_agent_cron_signal_away`');
        try {
            $loader->load(self::NODE, 'hil-1154.jsonl', $lines);
            $this->fail('The load wrote into a table that is gone');
        } catch (DatabaseException) {
            // The failure under test.
        } finally {
            Database::sql('RENAME TABLE `hilos_analytics_agent_cron_signal_away` TO `hilos_analytics_agent_cron_signal`');
        }

        foreach (['worker_session', 'agent_session', 'signal_name', 'agent_system_signal', 'journal_file'] as $table) {
            $this->assertSame([['0']], $this->rows("SELECT COUNT(*) FROM `hilos_analytics_{$table}`"), $table);
        }
        $this->assertSame([['0']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_loss`'));

        $outcome = $loader->load(self::NODE, 'hil-1154.jsonl', $lines);

        $this->assertFalse($outcome->alreadyLoaded);
        $this->assertSame(
            [[self::AGENT_KEY, self::WORKER_KEY, 'hil_1154_system']],
            $this->rows('SELECT LOWER(HEX(a.`session_key`)), LOWER(HEX(w.`session_key`)), n.`name`
                FROM `hilos_analytics_agent_system_signal` f
                JOIN `hilos_analytics_agent_session` a ON a.`id` = f.`agent_session_id`
                JOIN `hilos_analytics_worker_session` w ON w.`id` = a.`worker_session_id`
                JOIN `hilos_analytics_signal_name` n ON n.`id` = f.`signal_name_id`'),
        );
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_agent_cron_signal`'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testARenameOntoATakenTokenMergesTheVisit(): void
    {
        $outcome = $this->load([
            AnalyticsJournalRecord::browserSessionIdentity(self::TOKEN, 'user_id', '1', self::STARTED_TS),
            AnalyticsJournalRecord::browserSessionIdentity(self::ROTATED_TOKEN, 'user_id', '1', self::STARTED_TS + 1),
            AnalyticsJournalRecord::browserSessionRename(self::TOKEN, self::ROTATED_TOKEN, self::STARTED_TS + 2),
        ]);

        $this->assertSame([], $outcome->skipped);
        $this->assertSame(
            [[self::ROTATED_TOKEN]],
            $this->rows('SELECT `session_token` FROM `hilos_analytics_browser_session` ORDER BY `session_token`'),
        );
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testMasterRecordsLoadAfterAnAttachmentAndRepeatedKeysDoNotDuplicateRows(): void
    {
        $pageKey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $actionKey = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $requestKey = 'cccccccccccccccccccccccccccccccc';
        $request = new AnalyticsApiRequest(
            $requestKey, self::TOKEN, 'GET', '/health', ['from' => 'test'], 'UA/1.0', 'en', self::STARTED_TS,
        );
        $this->load([
            AnalyticsJournalRecord::wsConnectionAttach(self::ACCEPT_KEY, self::TOKEN, null, null, self::STARTED_TS),
            AnalyticsJournalRecord::wsConnectionOpen(self::ACCEPT_KEY, '127.0.0.1', self::STARTED_TS + 1),
            AnalyticsJournalRecord::pageSessionOpen($pageKey, self::ACCEPT_KEY, 'chat', ['room' => 1], self::STARTED_TS + 2),
            AnalyticsJournalRecord::pageSessionUpdate($pageKey, ['room' => 2], self::STARTED_TS + 3),
            AnalyticsJournalRecord::userAction($actionKey, self::ACCEPT_KEY, $pageKey, 'send', ['body' => 'hi'], self::STARTED_TS + 4),
            AnalyticsJournalRecord::wsConnectionIpChange(self::ACCEPT_KEY, '127.0.0.2', self::STARTED_TS + 5),
            AnalyticsJournalRecord::pageSessionClose($pageKey, self::STARTED_TS + 6),
            AnalyticsJournalRecord::wsConnectionClose(self::ACCEPT_KEY, self::STARTED_TS + 7),
            AnalyticsJournalRecord::apiRequest($request, 200, 12, self::STARTED_TS + 8),
        ]);
        $this->load([
            AnalyticsJournalRecord::pageSessionOpen($pageKey, self::ACCEPT_KEY, 'chat', ['room' => 1], self::STARTED_TS + 2),
            AnalyticsJournalRecord::userAction($actionKey, self::ACCEPT_KEY, $pageKey, 'send', ['body' => 'hi'], self::STARTED_TS + 4),
            AnalyticsJournalRecord::apiRequest($request, 200, 12, self::STARTED_TS + 8),
        ]);

        $this->assertSame([['1', '127.0.0.1', self::TOKEN]], $this->rows(
            'SELECT COUNT(*), INET_NTOA(`opened_ipv4`), b.`session_token`
             FROM `hilos_analytics_ws_connection` c
             JOIN `hilos_analytics_browser_session` b ON b.`id` = c.`browser_session_id`
             GROUP BY b.`session_token`, c.`opened_ipv4`',
        ));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_page_session`'));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_user_action`'));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_api_request`'));
        $this->assertSame([['127.0.0.2']], $this->rows(
            'SELECT INET_NTOA(`new_ipv4`) FROM `hilos_analytics_ws_connection_ipv4_change`',
        ));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testUnknownConnectionIsCountedForItsDependentRecords(): void
    {
        $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $outcome = $this->load([
            AnalyticsJournalRecord::wsConnectionIpChange(self::ACCEPT_KEY, '127.0.0.1', self::STARTED_TS),
            AnalyticsJournalRecord::pageSessionOpen($key, self::ACCEPT_KEY, 'chat', null, self::STARTED_TS),
            AnalyticsJournalRecord::userAction($key, self::ACCEPT_KEY, null, 'send', null, self::STARTED_TS),
        ]);
        $this->assertSame([AnalyticsJournalSkip::UNKNOWN_CONNECTION->value => 3], $outcome->skipped);
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testAnAddressChangeAfterAWriterRestartUsesTheLastStoredAddress(): void
    {
        $this->load([
            AnalyticsJournalRecord::wsConnectionOpen(self::ACCEPT_KEY, '127.0.0.1', self::STARTED_TS),
            AnalyticsJournalRecord::wsConnectionIpChange(self::ACCEPT_KEY, '127.0.0.2', self::STARTED_TS + 1),
        ]);
        $this->load([
            AnalyticsJournalRecord::wsConnectionIpChange(self::ACCEPT_KEY, '127.0.0.2', self::STARTED_TS + 2),
            AnalyticsJournalRecord::wsConnectionIpChange(self::ACCEPT_KEY, '127.0.0.3', self::STARTED_TS + 3),
        ]);
        $this->assertSame([
            ['127.0.0.1', '127.0.0.2'],
            ['127.0.0.2', '127.0.0.3'],
        ], $this->rows('SELECT INET_NTOA(`old_ipv4`), INET_NTOA(`new_ipv4`)
            FROM `hilos_analytics_ws_connection_ipv4_change` ORDER BY `id`'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testAgentFactsLoadedBeforeTheirCausesKeepTheirRowsAndLinkWhenTheCausesArrive(): void
    {
        $outcome = $this->load([
            ...$this->sessions(),
            AnalyticsJournalRecord::agentUserAction(self::AGENT_KEY, self::ACTION_KEY, 'hil_1154_action', null, self::STARTED_TS),
            AnalyticsJournalRecord::apiAgentAction(self::REQUEST_KEY, self::AGENT_KEY, 'hil_1154_api', null, self::STARTED_TS),
        ]);

        $this->assertSame([], $outcome->skipped);
        $this->assertSame([[null]], $this->rows('SELECT `user_action_id` FROM `hilos_analytics_agent_user_action`'));
        $this->assertSame([[null]], $this->rows('SELECT `api_request_id` FROM `hilos_analytics_api_agent_action`'));

        $request = new AnalyticsApiRequest(self::REQUEST_KEY, null, 'GET', '/health', null, null, null, self::STARTED_TS);
        $this->load([
            AnalyticsJournalRecord::wsConnectionOpen(self::ACCEPT_KEY, null, self::STARTED_TS),
            AnalyticsJournalRecord::userAction(self::ACTION_KEY, self::ACCEPT_KEY, null, 'send', null, self::STARTED_TS),
            AnalyticsJournalRecord::apiRequest($request, 200, 1, self::STARTED_TS + 1),
        ]);
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_agent_user_action` WHERE `user_action_id` IS NOT NULL'));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_api_agent_action` WHERE `api_request_id` IS NOT NULL'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testAgentFactsLinkToCausesLoadedFirst(): void
    {
        $request = new AnalyticsApiRequest(self::REQUEST_KEY, null, 'GET', '/health', null, null, null, self::STARTED_TS);
        $this->load([
            AnalyticsJournalRecord::wsConnectionOpen(self::ACCEPT_KEY, null, self::STARTED_TS),
            AnalyticsJournalRecord::userAction(self::ACTION_KEY, self::ACCEPT_KEY, null, 'send', null, self::STARTED_TS),
            AnalyticsJournalRecord::apiRequest($request, 200, 1, self::STARTED_TS + 1),
        ]);
        $this->load([
            ...$this->sessions(),
            AnalyticsJournalRecord::agentUserAction(self::AGENT_KEY, self::ACTION_KEY, 'hil_1154_action', null, self::STARTED_TS),
            AnalyticsJournalRecord::apiAgentAction(self::REQUEST_KEY, self::AGENT_KEY, 'hil_1154_api', null, self::STARTED_TS),
        ]);
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_agent_user_action` WHERE `user_action_id` IS NOT NULL'));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_api_agent_action` WHERE `api_request_id` IS NOT NULL'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testWhatCannotBeAppliedIsPassedOverAndTheFileStillLoads(): void
    {
        $lines = $this->lines([
            ...$this->sessions(),
            ['t' => 'hil_1154_unknown'],
            AnalyticsJournalRecord::agentSystemSignal('00000000000000000000000000000000', 'hil_1154_system', null, self::STARTED_TS),
            [AnalyticsJournalRecord::KEY_TYPE => AnalyticsJournalRecord::TYPE_AGENT_SYSTEM_SIGNAL, AnalyticsJournalRecord::KEY_AGENT_KEY => 'x'],
            // Larger than the INT UNSIGNED column it goes into: the database would refuse the whole file.
            AnalyticsJournalRecord::workerSession('11111111111111111111111111111111', 4_294_967_296, false, self::STARTED_TS),
            AnalyticsJournalRecord::agentSystemSignal(self::AGENT_KEY, 'hil_1154_system', null, self::STARTED_TS),
        ]);
        // The tail a machine crash leaves: the last line cut in half.
        $lines[] = '{"t":"agent_system_signal","agentKey":"' . self::AGENT_KEY;

        $outcome = $this->loader()->load(self::NODE, 'hil-1154.jsonl', $lines);

        $this->assertFalse($outcome->alreadyLoaded);
        $this->assertSame(
            [
                AnalyticsJournalSkip::UNKNOWN_TYPE->value => 1,
                AnalyticsJournalSkip::UNKNOWN_SESSION->value => 1,
                AnalyticsJournalSkip::MALFORMED->value => 3,
            ],
            $outcome->skipped,
        );
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_agent_system_signal`'));
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_journal_file`'));
    }

    /**
     * @throws HilosException When a load fails
     */
    public function testLastSeenOfABrowserSessionNeverMovesBack(): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection` (`accept_key`, `opened_ts`) VALUES (?, ?)',
            [self::ACCEPT_KEY, self::STARTED_TS],
        );

        $this->load([
            AnalyticsJournalRecord::wsConnectionAttach(self::ACCEPT_KEY, self::TOKEN, null, null, self::STARTED_TS + 100),
            AnalyticsJournalRecord::browserSessionIdentity(self::TOKEN, 'user_id', '1', self::STARTED_TS + 50),
        ]);

        $this->assertSame(
            [[(string)(self::STARTED_TS + 100)]],
            $this->rows('SELECT `last_seen_ts` FROM `hilos_analytics_browser_session`'),
        );
    }

    /**
     * @return list<array<string, mixed>> A worker session description and an agent session under it
     */
    private function sessions(): array
    {
        return [
            AnalyticsJournalRecord::workerSession(self::WORKER_KEY, self::WORKER_INDEX, true, self::STARTED_TS),
            AnalyticsJournalRecord::agentSession(self::AGENT_KEY, self::WORKER_KEY, self::AGENT_TYPE, '7', self::STARTED_TS),
        ];
    }

    /**
     * Loads the records as one file of its own name.
     *
     * @param list<array<string, mixed>> $records Records of the file, in order
     * @param ?AnalyticsJournalLoader $loader Loader to load with; a fresh one when null
     * @return AnalyticsJournalLoadOutcome What the load did
     * @throws HilosException When the load fails
     */
    private function load(array $records, ?AnalyticsJournalLoader $loader = null): AnalyticsJournalLoadOutcome
    {
        $this->fileNumber++;

        return ($loader ?? $this->loader())->load(self::NODE, $this->lastFileName(), $this->lines($records));
    }

    /**
     * @return string Name of the file {@see self::load()} loaded last
     */
    private function lastFileName(): string
    {
        return sprintf('%012d-0000000000000000.jsonl', $this->fileNumber);
    }

    /**
     * @return AnalyticsJournalLoader A loader over a store that remembers nothing yet
     */
    private function loader(): AnalyticsJournalLoader
    {
        return new AnalyticsJournalLoader(new AnalyticsStore());
    }

    /**
     * @param list<array<string, mixed>> $records Records
     * @return list<string> Their lines
     */
    private function lines(array $records): array
    {
        $lines = [];
        foreach ($records as $record) {
            $line = AnalyticsJournalRecord::encode($record);
            $this->assertNotNull($line);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param string $sql Query
     * @return list<list<?string>> Every row as a list of its values
     * @throws DatabaseException When the query fails
     */
    private function rows(string $sql): array
    {
        Database::sql($sql);

        $rows = [];
        foreach (Database::rows() as $row) {
            $rows[] = array_map(static fn(mixed $value): ?string => $value === null ? null : (string)$value, array_values($row));
        }

        return $rows;
    }
}

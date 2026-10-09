<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Core\Router\PollsSignalRouter;
use Demo\Polls\Hilos;
use Demo\Polls\Pages\Hilos\SettingsPage;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalLoader;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\RandomHelper;

/** The demo's actual settings action reaches analytics with an account identity and no body. */
final class AnalyticsActionWithoutBodyTest extends IntegrationTestCase
{
    private const int USER_ID = 1416;

    private string $acceptKey;

    private string $sessionToken;

    private AnalyticsCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::initSignalRouter(new PollsSignalRouter());
        $this->collector = new AnalyticsCollector();
        $this->acceptKey = 'hil-1416-' . RandomHelper::hex(8);
        $this->sessionToken = RandomHelper::hex(16);
        $this->collector->openWsConnection($this->acceptKey, null);
    }

    /** @throws DatabaseException When the case's analytics rows cannot be removed. */
    protected function tearDown(): void
    {
        Database::sql('DELETE FROM `hilos_analytics_ws_connection` WHERE `accept_key` = ?', [$this->acceptKey]);
        Database::sql('DELETE FROM `hilos_analytics_browser_session` WHERE `session_token` = ?', [$this->sessionToken]);
        Database::sql('DELETE FROM `hilos_analytics_journal_file` WHERE `file_name` = ?', [$this->acceptKey . '-file']);
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * @throws HilosException When the journal cannot be loaded or the recorded action read
     */
    public function testAccountActionKeepsItsNameAndIdentityWithoutItsBody(): void
    {
        $this->assertArrayHasKey(HilosSignalConstants::SETTING_UPDATE, SettingsPage::ACTIONS);
        $this->assertSame(SettingsPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::SETTING_UPDATE]);

        $this->collector->attachWsConnectionToBrowserSession($this->acceptKey, $this->sessionToken, null, null);
        $this->collector->identifyBrowserSessionUser($this->sessionToken, self::USER_ID);
        $actionKey = $this->collector->logUserAction(
            $this->acceptKey,
            HilosSignalConstants::SETTING_UPDATE,
            ['key' => 'example_string', 'value' => 'hil-1416-private-body'],
        );
        $this->assertNotNull($actionKey);
        $lines = $this->journalLines();
        $this->assertStringNotContainsString('hil-1416-private-body', implode('', $lines));
        $outcome = (new AnalyticsJournalLoader(new AnalyticsStore()))->load('', $this->acceptKey . '-file', $lines);
        $this->assertSame([], $outcome->skipped);

        Database::sql(
            'SELECT n.`name`, a.`payload_json_id`, b.`user_identity_type`, b.`user_identity_value`
             FROM `hilos_analytics_user_action` a
             JOIN `hilos_analytics_action_name` n ON n.`id` = a.`action_name_id`
             JOIN `hilos_analytics_ws_connection` c ON c.`id` = a.`ws_connection_id`
             JOIN `hilos_analytics_browser_session` b ON b.`id` = c.`browser_session_id`
             WHERE a.`action_key` = UNHEX(?)',
            [$actionKey],
        );
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame(HilosSignalConstants::SETTING_UPDATE, $row['name']);
        $this->assertNull($row['payload_json_id']);
        $this->assertSame('user_id', $row['user_identity_type']);
        $this->assertSame((string)self::USER_ID, $row['user_identity_value']);
    }

    /**
     * Takes the batches queued for the node journal.
     *
     * @return list<string> Journal lines in order
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
}

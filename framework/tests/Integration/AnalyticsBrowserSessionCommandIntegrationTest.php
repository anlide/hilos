<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Hilos\AbstractHilosAnalyticsAgent;
use Hilos\Core\Analytics\AnalyticsCommandConstants;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/** The command reads loaded facts and answers through the real command-reply queue. */
final class AnalyticsBrowserSessionCommandIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    /** @throws HilosException When the shipped account stub cannot be prepared */
    protected function setUp(): void
    {
        parent::setUp();
        Database::sql('DROP TABLE IF EXISTS `hilos_user`');
        Database::sql((string)file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_user.sql'));
    }

    /** @throws HilosException When the fixture schema cannot be removed */
    protected function tearDown(): void
    {
        Database::sql('DROP TABLE IF EXISTS `hilos_user`');
        parent::tearDown();
    }

    /** @throws HilosException When a fixture or command read fails */
    public function testNoMatchingSessionReturnsNullAndNoNames(): void
    {
        $this->session('another-person-secret', 8, 900);
        $reply = $this->probe();

        self::assertTrue($reply->isOk());
        self::assertSame(['browserSessionId' => null, 'actionNames' => []], $reply->payload);
    }

    /** @throws HilosException When a fixture or command read fails */
    public function testExistingEmptySessionKeepsItsNumber(): void
    {
        $sessionId = $this->session('empty-session-secret', 7, 200);
        $reply = $this->probe();

        self::assertTrue($reply->isOk());
        self::assertSame(['browserSessionId' => $sessionId, 'actionNames' => []], $reply->payload);
    }

    /** @throws HilosException When a fixture or command read fails */
    public function testOnlyLatestSessionIsReadAndNamesAreBoundedInSourceTimeOrder(): void
    {
        $tiedSession = $this->session('tied-session-secret', 7, 300);
        $latestSession = $this->session('latest-session-secret', 7, 300);
        $olderSession = $this->session('older-session-secret', 7, 200);
        $otherUserSession = $this->session('other-user-secret', 8, 900);
        $this->action($tiedSession, 'tied-session-action', 1);
        $this->action($olderSession, 'older-session-action', 1);
        $this->action($otherUserSession, 'other-user-action', 1);
        for ($moment = 55; $moment >= 1; $moment--) {
            $this->action($latestSession, 'action-' . $moment, $moment);
        }

        $reply = $this->probe();

        self::assertTrue($reply->isOk());
        self::assertSame([
            'browserSessionId' => $latestSession,
            'actionNames' => array_map(static fn(int $moment): string => 'action-' . $moment, range(1, 50)),
        ], $reply->payload);
    }

    /** @throws HilosException When fixture setup or the deliberate SQL failure cannot be arranged */
    public function testFailedSessionReadIsLoggedAndAnsweredAsError(): void
    {
        Database::sql('RENAME TABLE `hilos_analytics_browser_session` TO `probe_browser_session_unavailable`');
        try {
            $agent = new AnalyticsBrowserSessionProbeAgent();
            $reply = $this->probe($agent);

            $this->assertReadFailure($reply, $agent);
        } finally {
            Database::sql('RENAME TABLE `probe_browser_session_unavailable` TO `hilos_analytics_browser_session`');
        }
    }

    /** @throws HilosException When fixture setup or the deliberate SQL failure cannot be arranged */
    public function testFailedActionReadDoesNotReturnAnEmptySuccess(): void
    {
        $this->action($this->session('failed-action-read-secret', 7, 300), 'recorded-action', 100);
        Database::sql('RENAME TABLE `hilos_analytics_action_name` TO `probe_action_name_unavailable`');
        try {
            $agent = new AnalyticsBrowserSessionProbeAgent();
            $reply = $this->probe($agent);

            $this->assertReadFailure($reply, $agent);
        } finally {
            Database::sql('RENAME TABLE `probe_action_name_unavailable` TO `hilos_analytics_action_name`');
        }
    }

    /**
     * @param ?AnalyticsBrowserSessionProbeAgent $agent Agent whose errors the case may inspect
     * @return CommandReplyDTO Exactly one reply from the analytics agent
     * @throws HilosException When the command cannot be answered
     */
    private function probe(?AnalyticsBrowserSessionProbeAgent $agent = null): CommandReplyDTO
    {
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        ($agent ?? new AnalyticsBrowserSessionProbeAgent())->onSignalCommand(
            new CommandRequestDTO('analytics-probe', AnalyticsCommandConstants::TEST_BROWSER_SESSION, ['userId' => 7]),
            '',
            '',
        );
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal, 'Every command branch must answer');
        self::assertSame(SignalTypeConstants::COMMAND_REPLY, $signal->signalType->getType());
        self::assertSame('analytics-probe', $signal->signalName->getName());
        self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
        self::assertSame('analytics-probe', $signal->data->correlationId);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No branch may answer twice');

        return $signal->data;
    }

    /**
     * @param CommandReplyDTO $reply Failed command reply
     * @param AnalyticsBrowserSessionProbeAgent $agent Agent that logged the read failure
     */
    private function assertReadFailure(CommandReplyDTO $reply, AnalyticsBrowserSessionProbeAgent $agent): void
    {
        self::assertFalse($reply->isOk());
        self::assertSame([CommandConstants::FIELD_MESSAGE], array_keys($reply->payload));
        self::assertStringContainsString('Cannot read analytics browser session:', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertStringContainsString('hilos_analytics_', $reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertCount(1, $agent->errors);
        self::assertStringContainsString('Analytics browser-session probe failed:', $agent->errors[0]);
    }

    /**
     * @param string $token Private fixture token
     * @param int $userId Last signed-in account number
     * @param int $lastSeenTs Last source moment
     * @return int Persisted browser-session number
     * @throws HilosException When the fixture cannot be inserted
     */
    private function session(string $token, int $userId, int $lastSeenTs): int
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_browser_session`
                (`session_token`, `user_identity_type`, `user_identity_value`, `first_seen_ts`, `last_seen_ts`)
             VALUES (?, ?, ?, ?, ?)',
            [$token, 'user_id', (string)$userId, 1, $lastSeenTs],
        );
        $sessionId = Database::lastInsertId();
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection` (`browser_session_id`, `accept_key`, `opened_ts`) VALUES (?, ?, ?)',
            [$sessionId, 'private-accept-key-' . $token, 1],
        );

        return $sessionId;
    }

    /**
     * @param int $sessionId Browser session with exactly one fixture connection
     * @param string $name Recorded action name
     * @param int $createdTs Source moment
     * @throws HilosException When the fixture cannot be inserted
     */
    private function action(int $sessionId, string $name, int $createdTs): void
    {
        Database::sql('INSERT INTO `hilos_analytics_action_name` (`name`, `created_ts`) VALUES (?, ?)', [$name, $createdTs]);
        Database::sql(
            'INSERT INTO `hilos_analytics_user_action` (`ws_connection_id`, `action_name_id`, `created_ts`)
             SELECT `id`, ?, ? FROM `hilos_analytics_ws_connection` WHERE `browser_session_id` = ?',
            [Database::lastInsertId(), $createdTs, $sessionId],
        );
    }
}

/** The real reply queue, with error logging captured for assertion. */
final class AnalyticsBrowserSessionProbeAgent extends AbstractHilosAnalyticsAgent
{
    /** @var list<string> Read failures logged during the current command */
    public array $errors = [];

    /** @param string $message Read failure to capture */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

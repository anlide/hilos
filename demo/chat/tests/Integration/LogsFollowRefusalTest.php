<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Logs\LogsViewPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsFollowStartActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;

/**
 * A live log follow on the chat logs page ends when the server refuses the page.
 *
 * With the view mode off, taking the admin flag is answered with 403. The refusal
 * releases the follow at the file's owner, and a line written after that reaches
 * the connection in no frame.
 */
final class LogsFollowRefusalTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'logs-follow-refusal-test';

    private const string ACCEPT_KEY = 'logs-follow-refusal-admin';

    private const string REQUEST_ID = 'req-logs-follow-refusal';

    private const string STREAM = 'worker-0.log';

    /** Text of the line written while the follow is live. */
    private const string FOLLOWED_TEXT = 'arrived while followed';

    private int $adminId = 0;

    private string $dir = '';

    private string $journal = '';

    private bool $journalRedirected = false;

    /** @var string|false|null False when the variable was unset; null before capture */
    private string|false|null $previousDaemonLog = null;

    /**
     * Points the log owner at a temporary directory and opens an admin connection with the view mode off.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDaemonLog = getenv(EnvConstants::DAEMON_LOG_FILE->name);
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logs-follow-refusal-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            self::fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the directory of its own journal.
        $this->journal = (string) tempnam(sys_get_temp_dir(), 'hilos-logs-follow-refusal-journal');
        Logger::setLogFile($this->journal);
        $this->journalRedirected = true;
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');

        Hilos::initBrowser();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);

        $admin = Hilos::$db->users->actions->createWithName('Logs follow admin');
        $admin->actions->setAdmin(true);
        $this->adminId = (int) $admin->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->adminId);
        $this->drainSignals();
    }

    /**
     * Puts the log directory, the view-mode claim and the admin row back.
     */
    protected function tearDown(): void
    {
        new LogsViewPage(new DemoHilosAgent())->onUnsubscribe(self::ACCEPT_KEY);
        if ($this->previousDaemonLog === false) {
            putenv(EnvConstants::DAEMON_LOG_FILE->name);
        } elseif ($this->previousDaemonLog !== null) {
            putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->previousDaemonLog);
        }
        if ($this->journalRedirected) {
            Logger::resetLogFile();
        }
        if ($this->journal !== '' && is_file($this->journal)) {
            unlink($this->journal);
        }
        $this->removeTree($this->dir);

        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::initBrowser();
        $this->drainSignals();
        Hilos::$sr = null;
        if ($this->adminId !== 0 && isset(Hilos::$db->users[$this->adminId])) {
            Hilos::$db->users[$this->adminId]->actions->delete();
        }

        parent::tearDown();
    }

    public function testWithTheModeOffRevokingAdminRefusesTheOpenFollowAndSendsNothingFurther(): void
    {
        $this->write("[2026-08-01 00:00:00.000] already on the file\n");
        $names = array_map(
            static fn (SignalDTO $signal): string => $signal->signalName->getName(),
            $this->subscribe(),
        );
        self::assertContains(SignalTypeConstants::PAGE_RESPONSE, $names);
        self::assertNotContains(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $names);

        $start = $this->startFollow();
        $store = new LogStoreAgent();
        $store->onSignalAgent($start['carrier'], 'agent', $start['name']);
        $this->drainSignals();

        $this->append("[2026-08-01 00:00:01.000] " . self::FOLLOWED_TEXT . "\n");
        $store->pushAppendedLines();
        $live = $this->appendedToConnection();
        self::assertFalse($live->stopped);
        self::assertSame(self::REQUEST_ID, $live->followId);
        self::assertSame([self::FOLLOWED_TEXT], array_column($live->lines, LogsReadLinesReplyDTO::text));

        Hilos::$db->users[$this->adminId]->actions->setAdmin(false);
        $this->drainSignals();
        $refusal = $this->reassess();

        self::assertSame(HttpConstants::HTTP_FORBIDDEN, $refusal['error']->httpCode);
        self::assertSame(self::ACCEPT_KEY, $refusal['stop']->acceptKey);
        $store->onSignalAgent($refusal['carrier'], 'agent', HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP);
        $stopped = $this->appendedToConnection();
        self::assertTrue($stopped->stopped);
        self::assertSame(self::REQUEST_ID, $stopped->followId);

        $this->append("[2026-08-01 00:00:02.000] arrived after the refusal\n");
        $store->pushAppendedLines();
        $this->assertConnectionReceivedNothing();
    }

    /**
     * Subscribes the admin to the logs viewer the way the page router records a subscription.
     *
     * @return list<SignalDTO> Frames the subscription queued
     */
    private function subscribe(): array
    {
        $this->drainSignals();
        Hilos::$sr->subscribeToPage(
            LogsViewPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, LogsViewPage::PAGE, []),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function (): void {
            new LogsViewPage(new DemoHilosAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
        });

        return $this->drainQueued();
    }

    /**
     * Starts a follow through the page router and returns the frame it forwarded to the owner.
     *
     * @return array{name: string, carrier: AgentSignalData} Forwarded start
     */
    private function startFollow(): array
    {
        $this->drainSignals();
        $payload = new LogsFollowStartActionDTO('', self::STREAM, null, null);
        $agent = new DemoHilosLegalAgent();
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig([HilosSignalConstants::LOGS_FOLLOW_START => LogsViewPage::PAGE]),
        );
        $this->underAgent($agent, static fn () => $router->dispatchAction(
            new WebSocketActionSignalDTO(
                self::ACCEPT_KEY,
                HilosSignalConstants::LOGS_FOLLOW_START,
                $payload->toArray(),
                self::REQUEST_ID,
            ),
            'websocket',
        ));

        foreach ($this->drainQueued() as $signal) {
            if (!$signal->data instanceof AgentSignalData || !$signal->data->data instanceof LogsFollowStartSignalData) {
                continue;
            }
            self::assertSame(HilosSignalConstants::LOGS_AGENT_FOLLOW_START, $signal->signalName->getName());
            self::assertSame(self::ACCEPT_KEY, $signal->data->data->acceptKey);
            self::assertSame(self::STREAM, $signal->data->data->stream);
            self::assertSame(self::REQUEST_ID, $signal->data->data->requestId);

            return ['name' => $signal->signalName->getName(), 'carrier' => $signal->data];
        }

        self::fail('The follow start reached no owner');
    }

    /**
     * Re-decides the open logs page and returns the refusal plus the stop it owed the owner.
     *
     * @return array{error: PageSubscriptionErrorSignalData, stop: LogsFollowStopSignalData, carrier: AgentSignalData}
     *     The 403 and the stop frame
     */
    private function reassess(): array
    {
        $this->drainSignals();
        new PageSignalRouter(new HilosPageFactory(new DemoHilosLegalAgent(), Hilos::class), new ActionRouteConfig())
            ->dispatchPageAccessReassess(
                new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, LogsViewPage::PAGE, []),
                'websocket',
                LogsViewPage::PAGE,
            );

        $error = null;
        $stop = null;
        $carrier = null;
        foreach ($this->drainQueued() as $signal) {
            if (
                $signal->signalName->getName() === SignalConstants::SUBSCRIPTION_PAGE_ERROR
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->targetAcceptKey === self::ACCEPT_KEY
                && $signal->data->data instanceof PageSubscriptionErrorSignalData
            ) {
                $error = $signal->data->data;
            }
            if (
                $signal->signalName->getName() === HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP
                && $signal->data instanceof AgentSignalData
                && $signal->data->data instanceof LogsFollowStopSignalData
            ) {
                $stop = $signal->data->data;
                $carrier = $signal->data;
            }
        }
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $error);
        self::assertInstanceOf(LogsFollowStopSignalData::class, $stop);
        self::assertInstanceOf(AgentSignalData::class, $carrier);

        return ['error' => $error, 'stop' => $stop, 'carrier' => $carrier];
    }

    /**
     * @return list<SignalDTO> Frames left in the queue, in delivery order
     */
    private function drainQueued(): array
    {
        $queued = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $queued[] = $signal;
        }

        return $queued;
    }

    /**
     * Reads the one appended-lines frame addressed to the admin connection.
     *
     * @return LogsLinesAppendedSignalData Frame the owner sent
     */
    private function appendedToConnection(): LogsLinesAppendedSignalData
    {
        $found = null;
        foreach ($this->drainQueued() as $signal) {
            if ($signal->signalName->getName() !== HilosSignalConstants::LOGS_LINES_APPENDED) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
            self::assertNull($found, 'The connection was sent more than one appended-lines frame');
            self::assertInstanceOf(LogsLinesAppendedSignalData::class, $signal->data->data);
            $found = $signal->data->data;
        }
        self::assertInstanceOf(LogsLinesAppendedSignalData::class, $found);

        return $found;
    }

    /**
     * Asserts the push after the stop queued nothing for the admin connection.
     */
    private function assertConnectionReceivedNothing(): void
    {
        foreach ($this->drainQueued() as $signal) {
            self::assertNotSame(HilosSignalConstants::LOGS_LINES_APPENDED, $signal->signalName->getName());
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertNotSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
            }
        }
    }

    /**
     * @param string $contents File contents
     */
    private function write(string $contents): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . self::STREAM;
        if (file_put_contents($path, $contents) === false) {
            self::fail("Could not write {$path}");
        }
    }

    /**
     * @param string $contents Bytes to append
     */
    private function append(string $contents): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . self::STREAM;
        if (file_put_contents($path, $contents, FILE_APPEND) === false) {
            self::fail("Could not append {$path}");
        }
    }

    /**
     * Removes the fixture directory and everything under it.
     *
     * @param string $path Directory to remove
     */
    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}

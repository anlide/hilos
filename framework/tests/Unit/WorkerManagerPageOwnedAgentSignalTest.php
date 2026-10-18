<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Page-owned answers pass through their serving agent without reaching its handler (HIL-1541).
 */
final class WorkerManagerPageOwnedAgentSignalTest extends TestCase
{
    private string $logDirectory;

    /** Creates a separate directory for the main log and the agent's error stream. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = sys_get_temp_dir() . '/hilos-page-owned-signal-' . bin2hex(random_bytes(8));
        mkdir($this->logDirectory, 0777, true);
        Logger::setLogFile($this->logDirectory . '/daemon.log');
    }

    /** Releases process-global routing and logging state after each delivery. */
    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Logger::resetLogFile();
        foreach (glob($this->logDirectory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->logDirectory);

        parent::tearDown();
    }

    public function testPageOwnedSignalReachesOnlyThePageWithoutAnAgentError(): void
    {
        $manager = new PageOwnedSignalTestWorkerManager();
        $data = new AgentSignalData(new SignalData(['message' => 'page answer']));

        $this->deliver($manager, PageOwnedSignalTestPage::SIGNAL, $data);

        $page = $manager->pageFactory->getPage(PageOwnedSignalTestPage::PAGE);
        $this->assertInstanceOf(PageOwnedSignalTestPage::class, $page);
        $this->assertSame(1, $page->signalCalls);
        $this->assertSame($data, $page->signalData);
        $errorLog = $this->logDirectory . '/agent-' . PageOwnedSignalTestAgent::AGENT_TYPE . '.error.log';
        if (is_file($errorLog)) {
            $content = file_get_contents($errorLog);
            $this->assertSame('', $content, "Agent error log {$errorLog}:\n{$content}");
        }
        $this->assertSame(0, $manager->agent->signalCalls);
    }

    public function testUnknownSignalStillLogsTheAgentRefusal(): void
    {
        $manager = new PageOwnedSignalTestWorkerManager();

        $this->deliver($manager, 'unknown_signal', new AgentSignalData(new SignalData()));

        $this->assertSame(1, $manager->agent->signalCalls);
        $page = $manager->pageFactory->getPage(PageOwnedSignalTestPage::PAGE);
        $this->assertInstanceOf(PageOwnedSignalTestPage::class, $page);
        $this->assertSame(0, $page->signalCalls);
        $errorLog = $this->logDirectory . '/agent-' . PageOwnedSignalTestAgent::AGENT_TYPE . '.error.log';
        $this->assertFileExists($errorLog);
        $content = file_get_contents($errorLog);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('Agent signal handler failed: Unknown agent signal: unknown_signal', $content);
    }

    public function testAgentOwnedSignalStillReachesTheAgentOnce(): void
    {
        $manager = new PageOwnedSignalTestWorkerManager();

        $this->deliver($manager, PageOwnedSignalTestAgent::SIGNAL, new AgentSignalData(new SignalData()));

        $this->assertSame(1, $manager->agent->signalCalls);
        $this->assertSame([PageOwnedSignalTestAgent::SIGNAL], $manager->agent->handledNames);
        $page = $manager->pageFactory->getPage(PageOwnedSignalTestPage::PAGE);
        $this->assertInstanceOf(PageOwnedSignalTestPage::class, $page);
        $this->assertSame(0, $page->signalCalls);
    }

    /**
     * @param WorkerManager $manager Worker receiving the daemon frame
     * @param string $name Agent-signal name
     * @param AgentSignalData $data Wrapped payload as delivered by the daemon
     */
    private function deliver(WorkerManager $manager, string $name, AgentSignalData $data): void
    {
        $handle = Closure::bind(
            static function (WorkerManager $manager, SignalDTO $signal): void {
                $manager->handleAgentMessage(new DaemonAgentMessageDTO(PageOwnedSignalTestAgent::AGENT_TYPE, $signal));
            },
            null,
            WorkerManager::class,
        );

        $handle($manager, new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'unit_sender'),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($name),
            $data,
        ));
    }
}

final class PageOwnedSignalTestWorkerManager extends WorkerManager
{
    public readonly PageOwnedSignalTestAgent $agent;
    public readonly PageOwnedSignalTestPageFactory $pageFactory;

    /** Installs a strict agent and its page factory in the real worker dispatcher. */
    public function __construct()
    {
        parent::__construct(1);
        $this->agent = new PageOwnedSignalTestAgent();
        $this->pageFactory = new PageOwnedSignalTestPageFactory($this->agent);
        $this->agentManager->addAgent(PageOwnedSignalTestAgent::AGENT_TYPE, $this->agent);
    }

    /** @return SignalRouter Router with no agent payload DTO declarations */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManager Agent manager populated by the fixture constructor */
    protected function createAgentManager(): AgentManager
    {
        return new PageOwnedSignalTestAgentManager();
    }

    /**
     * @param AgentInterface $agent Serving agent, already bound to the fixture factory
     * @return PageSignalRouter Router declaring one page-owned agent-signal name
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(
            $this->pageFactory,
            new ActionRouteConfig(),
            new SignalRouteConfig([
                SignalTypeConstants::AGENT_SIGNAL => [
                    PageOwnedSignalTestPage::SIGNAL => PageOwnedSignalTestPage::PAGE,
                ],
            ]),
        );
    }
}

final class PageOwnedSignalTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Fixture agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentInterface Strict fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new PageOwnedSignalTestAgent();
    }
}

final class PageOwnedSignalTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_page_owned_signal';
    public const string SIGNAL = 'agent_owned_signal';

    public int $signalCalls = 0;

    /** @var list<string> Names accepted by this agent */
    public array $handledNames = [];

    /** The fixture owns no resources to release. */
    public function onStop(): void
    {
    }

    /**
     * Counts every handler entry and refuses every name except the agent's own.
     *
     * @param AgentSignalData $data Wrapped payload, unused
     * @param string $sender Full sender address, unused
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When a signal belongs to somebody else
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        $this->signalCalls++;
        switch ($name) {
            case self::SIGNAL:
                $this->handledNames[] = $name;
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }
}

final class PageOwnedSignalTestPage extends AbstractPage
{
    public const string PAGE = 'page_owned_signal';
    public const string SIGNAL = 'page_owned_answer';

    public int $signalCalls = 0;
    public ?AgentSignalData $signalData = null;

    /**
     * @param AgentSignalData $data Wrapped page answer
     * @param string $sender Full sender address, unused
     * @param string $name Signal name
     * @throws AgentUnknownSignalException When the page receives an undeclared name
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case self::SIGNAL:
                $this->signalCalls++;
                $this->signalData = $data;
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }
}

/** @extends AbstractPageFactory<PageOwnedSignalTestAgent> */
final class PageOwnedSignalTestPageFactory extends AbstractPageFactory
{
    /**
     * @param string $pageName Requested page name
     * @return bool Whether the fixture page exists
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === PageOwnedSignalTestPage::PAGE;
    }

    /**
     * @param string $pageName Requested page name
     * @return AbstractPage Fixture page
     * @throws PageNotFoundException When a different page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === PageOwnedSignalTestPage::PAGE) {
            return new PageOwnedSignalTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }
}

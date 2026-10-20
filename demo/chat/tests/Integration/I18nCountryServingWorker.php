<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\HilosException;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;

/**
 * The chat worker's page port, holding the i18n library that serves country cards.
 *
 * Subscriptions and deletions go through the daemon's agent-message door, and the
 * browser is bound to this worker, so a deleted row is refused by the same page
 * router that answered the subscription.
 */
final class I18nCountryServingWorker extends WorkerManager
{
    private I18nLibraryAgent $library;

    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * @return I18nLibraryAgent Library this worker serves
     */
    public function library(): I18nLibraryAgent
    {
        return $this->library;
    }

    /**
     * Delivers one frame to the i18n library the way the daemon addresses this worker.
     *
     * @param string $type Signal type
     * @param SignalDataInterface $data Frame payload
     * @param string $name Signal name
     * @throws AgentCreationFailedException When agent creation fails
     * @throws PageSignalRouterNotFoundException When the agent has no page router
     * @throws TableRowKeyMissingException When a windowed table row carries no key
     * @throws InvalidArgumentException When a frame cannot be named
     * @throws HilosException When a collection refuses to be re-read
     */
    public function deliver(string $type, SignalDataInterface $data, string $name): void
    {
        $agentId = ExecutionContext::currentAgentId();
        $acceptKey = ExecutionContext::currentAcceptKey();
        try {
            $this->handleDaemonMessage(new DaemonAgentMessageDTO(
                $this->library->getId(),
                new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType($type),
                    new SignalName($name),
                    $data,
                ),
            ));
        } finally {
            ExecutionContext::setCurrentAgentId($agentId);
            ExecutionContext::setCurrentAcceptKey($acceptKey);
        }
    }

    /**
     * Records committed sync frames into the browser and puts every other frame back.
     *
     * The worker does this while draining the queue toward the daemon. There is no
     * daemon here, so the same recorder runs on the frames the commit already queued.
     * A hand-built fact is not a substitute: the origin is the one the write announced.
     *
     * @throws InvalidArgumentException When a restored frame cannot be named
     */
    public function recordCommittedSync(): void
    {
        $record = Closure::bind(
            static function (WorkerManager $manager, SyncSignalDataInterface $signalData): void {
                $manager->recordBrowserSourceChange($signalData);
            },
            null,
            WorkerManager::class,
        );
        $kept = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($record instanceof Closure && $signal->data instanceof SyncSignalDataInterface) {
                $record($this, $signal->data);

                continue;
            }
            $kept[] = $signal;
        }
        foreach ($kept as $signal) {
            Hilos::$sr?->queueSignal(
                $signal->signalSource,
                $signal->signalType,
                $signal->signalName,
                $signal->data,
            );
        }
    }

    /**
     * @return SignalRouter Chat signal router, the queue this worker drains
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * @return AgentManager Manager holding the one i18n library
     */
    protected function createAgentManager(): AgentManager
    {
        $library = new I18nLibraryAgent();
        $this->library = $library;

        return new I18nCountryServingAgentManager($library);
    }

    /**
     * @param AgentInterface $agent Agent receiving page signals
     * @return PageSignalRouter Router over the demo's page and action registry
     * @throws PageSignalRouterNotFoundException When the agent does not serve pages
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        if (!$agent instanceof PageAgentInterface) {
            throw new PageSignalRouterNotFoundException($agent::class);
        }

        return new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
            new SignalRouteConfig(Hilos::getPageSignalRoutes()),
        );
    }
}

/** Holds the library the serving worker was constructed with. */
final class I18nCountryServingAgentManager extends AgentManager
{
    /**
     * @param I18nLibraryAgent $library Agent this worker serves
     */
    public function __construct(private readonly I18nLibraryAgent $library)
    {
        $this->addAgent($this->library->getId(), $this->library);
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Never returns; the library is registered by hand
     * @throws AgentCreationFailedException Always, because no second agent is created here
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new AgentCreationFailedException($agentType, $agentIndex);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Legal\Export\DTO\LegalAcceptancesExportForgetSignalData;
use Hilos\Legal\Export\LegalAcceptancesExportHttp;
use Hilos\Legal\Export\LegalAcceptancesExports;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use LogicException;
use Random\RandomException;

/**
 * Serves the five legal pages and holds their acceptance histograms in its own process.
 *
 * It owns the administrators' exports of acceptance records too: the orders, the files built a part per
 * tick and their download address (HIL-1234). Projects extend and register it with a monopolistic daemon,
 * or omit the legal pages; a project registering it starts it with the node, so a file outlives its day
 * no longer than an hour whether or not anybody opens the section. Whole-history SQL aggregates must not
 * delay the shared administration worker's tick.
 */
abstract class AbstractHilosLegalAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LEGAL;

    public const array OWNS_DB = [HilosDbContext::legalAcceptanceExports => TruthSourceOperation::ALL];

    /**
     * Interest is raised before construction, so remote writes reach the histogram subscriber. The people's
     * emails go into the exports, and the sessions and confirmations gate who orders and who downloads; the
     * second factors and device keys are what the step-up gate asks to name the proof of an order it refuses.
     */
    public const array READS_DB = [
        HilosDbContext::legalAcceptances,
        HilosDbContext::identities,
        HilosDbContext::sessions,
        HilosDbContext::stepUps,
        HilosDbContext::secondFactors,
        HilosDbContext::passkeyCredentials,
    ];

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET => LegalAcceptancesExportForgetSignalData::class,
    ];

    public const array AGENT_HTTP_ROUTES = [HttpConstants::METHOD_GET => [LegalAcceptancesExportHttp::DOWNLOAD_PATH]];

    /**
     * Reconciles the exports with their directory before the first order is served.
     *
     * @throws HilosException When the export directory, an order or a state frame cannot be read or written
     * @throws LogicException When an internal invariant is violated
     * @throws RandomException When inherited startup cannot draw secure entropy
     */
    public function onStart(): void
    {
        parent::onStart();
        LegalAcceptancesExports::onStart($this);
    }

    /**
     * Refreshes subscribed windows after acceptance changes or a new server date, then advances the exports.
     *
     * @throws HilosException When the parent tick, histogram read, window delivery or export queue fails
     * @throws LogicException When a project runtime item factory rejects a row
     */
    public function onTick(): void
    {
        parent::onTick();
        LegalAdminAudience::onAgentTick($this);
        LegalAcceptancesExports::onTick($this);
    }

    /** @return list<CronRule> Current legal export expiration schedule */
    protected function cronRules(): array
    {
        return LegalAcceptancesExports::cronRules();
    }

    /**
     * Removes the exports an erasure makes stale.
     *
     * One more name is taken and dropped: the settings library's answer to a legal setting write is
     * addressed to the settings page, and an agent-routed signal reaches this agent first and the page
     * after it, so the answer passes through here on its way there.
     *
     * @param AgentSignalData $data Typed erasure frame
     * @param string $sender Sending agent identity
     * @param string $name Declared signal name
     * @throws HilosException When the frame is unknown or malformed, or the exports cannot be removed
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET:
                if (!$data->data instanceof LegalAcceptancesExportForgetSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, LegalAcceptancesExportForgetSignalData::class, $data->data);
                }
                LegalAcceptancesExports::forget($this, $data->data->userId);

                return;

            case HilosSignalConstants::HILOS_LEGAL_SETTING_WRITE_DONE:
                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * @param HttpRequestDTO $data Request whose session selects the file
     * @param string $source Request source, unused
     * @param string $name Declared method and path, unused
     * @throws HilosException When the session, the export or the reply cannot be read or sent
     */
    public function onSignalHttpRequest(HttpRequestDTO $data, string $source, string $name): void
    {
        $this->replyToHttpRequest(LegalAcceptancesExports::serve($this, $data));
    }

    /**
     * @param WebSocketCloseSignalDTO $data Closed connection
     * @param string $source Signal origin
     * @param string $name Connection-close signal name
     * @throws HilosException When the parent close handler fails
     */
    public function onSignalConnectionClose(WebSocketCloseSignalDTO $data, string $source, string $name): void
    {
        parent::onSignalConnectionClose($data, $source, $name);
        LegalAdminAudience::removeSubscriber($data->acceptKey);
    }

    /**
     * Discards histograms, subscriptions and the export before another instance uses this worker.
     *
     * @throws HilosException When parent cleanup fails
     */
    public function onStop(): void
    {
        parent::onStop();
        LegalAdminAudience::reset();
        LegalAcceptancesExports::reset();
    }
}

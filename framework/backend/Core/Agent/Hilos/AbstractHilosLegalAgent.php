<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;

/**
 * Serves the five legal pages and holds their acceptance histograms in its own process.
 *
 * Projects extend and register it with a monopolistic daemon, or omit the legal pages.
 * Whole-history SQL aggregates must not delay the shared administration worker's tick.
 */
abstract class AbstractHilosLegalAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_LEGAL;

    /** Interest is raised before construction, so remote writes reach the histogram subscriber. */
    public const array READS_DB = [HilosDbContext::legalAcceptances];

    /**
     * Refreshes subscribed windows after acceptance changes or a new server date.
     *
     * @throws HilosException When the parent tick, histogram read or window delivery fails
     */
    public function onTick(): void
    {
        parent::onTick();
        LegalAdminAudience::onAgentTick($this);
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

    /** Discards histograms and subscriptions before another instance uses this worker. */
    public function onStop(): void
    {
        parent::onStop();
        LegalAdminAudience::reset();
    }
}

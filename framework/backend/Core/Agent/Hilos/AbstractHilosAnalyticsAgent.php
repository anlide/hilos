<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Analytics\AnalyticsCommandConstants;
use Hilos\Core\Analytics\AnalyticsSectionAction;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsWindowOrder;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Throwable;

/**
 * AbstractHilosAnalyticsAgent - Cluster reader for the Hilos analytics section.
 *
 * A project's thin subclass hosts its admin pages in a monopolistic worker. Their
 * bounded SQL reads go through the typed section reader, not the journal writer.
 */
abstract class AbstractHilosAnalyticsAgent extends AbstractHilosAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_ANALYTICS;

    public const array AGENT_COMMANDS = [
        ...parent::AGENT_COMMANDS,
        AnalyticsCommandConstants::TEST_BROWSER_SESSION,
    ];

    /**
     * @return AnalyticsSectionReader Read-only data source for pages served by this agent
     */
    public function sectionReader(): AnalyticsSectionReader
    {
        return new AnalyticsSectionReader();
    }

    /**
     * @param CommandRequestDTO $data Test-only read request
     * @param string $source Signal source, unused
     * @param string $name Signal name, unused; the request carries the command name
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case AnalyticsCommandConstants::TEST_BROWSER_SESSION:
                $this->handleBrowserSessionProbe($data);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * @param CommandRequestDTO $data Request carrying the last signed-in user's number
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    private function handleBrowserSessionProbe(CommandRequestDTO $data): void
    {
        $userId = $data->payload[AnalyticsCommandConstants::FIELD_USER_ID] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Analytics probe needs a positive integer userId'));

            return;
        }

        try {
            $reader = $this->sectionReader();
            $sessions = $reader->browserSessionWindow($userId, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 1);
            $sessionId = $sessions[0]->id ?? null;
            $actions = $sessionId === null ? [] : $reader->actionWindow(
                $sessionId,
                AnalyticsWindowOrder::TIME_ASC,
                null,
                TableAnchorDirection::After,
                AnalyticsSectionReader::MAX_PAGE_ROWS,
            );
        } catch (Throwable $e) {
            $this->logAgentError('Analytics browser-session probe failed: ' . $e->getMessage());
            $this->replyToCommand(CommandReplyDTO::error(
                $data->correlationId,
                'Cannot read analytics browser session: ' . $e->getMessage(),
            ));

            return;
        }

        $this->replyToCommand(CommandReplyDTO::ok($data->correlationId, [
            AnalyticsCommandConstants::FIELD_BROWSER_SESSION_ID => $sessionId,
            AnalyticsCommandConstants::FIELD_ACTION_NAMES => array_map(
                static fn(AnalyticsSectionAction $action): string => $action->actionName,
                $actions,
            ),
        ]));
    }
}

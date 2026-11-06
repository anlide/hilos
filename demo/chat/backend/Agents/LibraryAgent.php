<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\CLI\DTO\BotAnswerNowCommandData;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCommandConstants;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\DTO\BotAgentSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Environment\NonProductionGate;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * Monopolistic library worker for admin-managed chat catalog entities.
 *
 * Owns bot profiles, and so answers the bot's test-only answer-now command: whether a bot is active is read from bots, and a
 * signal to a stopped bot would start an agent that nothing stops afterwards.
 */
final class LibraryAgent extends AbstractAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The bot catalog its admin CRUD pages write
     */
    public const array OWNS_DB = [
        ChatDbContext::bots => TruthSourceOperation::BY_KIND,
    ];

    public const string AGENT_TYPE = AgentType::LIBRARY;

    public const array AGENT_COMMANDS = [
        ChatCommandConstants::BOT_TEST_ANSWER_NOW => BotAnswerNowCommandData::class,
    ];

    /** Catalog actions and commands arrive in frames; there is no periodic work. */
    protected const MAX_IDLE_SLEEP_SECONDS = 600;

    /**
     * Answers the test-only command that has one active bot answer now.
     *
     * @param CommandRequestDTO $data Topology-hydrated command
     * @param string $source Command source, unused
     * @param string $name Signal name, unused
     * @throws HilosException When the bot cannot be read, or the signal or the reply cannot be queued
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        switch ($data->command) {
            case ChatCommandConstants::BOT_TEST_ANSWER_NOW:
                if (!NonProductionGate::admitted()) {
                    $this->replyToCommand(CommandReplyDTO::error($data->correlationId, 'Bot answer-now is test-only'));

                    return;
                }
                $this->answerNow($data, $data->parsedPayload);

                return;

            default:
                $this->replyToCommand(CommandReplyDTO::error($data->correlationId, "Unknown command: {$data->command}"));
        }
    }

    /**
     * Library data is durable, so stop cleanup only unregisters truth sources via WorkerManager.
     */
    public function onStop(): void
    {
    }

    /**
     * Hands an active bot's agent the signal to go to the model on its next free tick; does not wait for the model.
     *
     * @param CommandRequestDTO $request Command to answer
     * @param BotAnswerNowCommandData $query Bot asked to answer now
     * @throws HilosException When the bot cannot be read, or the signal or the reply cannot be queued
     */
    private function answerNow(CommandRequestDTO $request, BotAnswerNowCommandData $query): void
    {
        if (!isset(Hilos::$db->bots[$query->botId]) || Hilos::$db->bots[$query->botId]->active !== true) {
            $this->replyToCommand(CommandReplyDTO::error($request->correlationId, "Bot {$query->botId} is not active"));

            return;
        }
        $this->sendToAgent(ChatSignalConstants::BOT_ANSWER_NOW, new BotAgentSignalData(botId: $query->botId));
        $this->replyToCommand(CommandReplyDTO::ok($request->correlationId, [ChatCommandConstants::botId => $query->botId]));
    }
}

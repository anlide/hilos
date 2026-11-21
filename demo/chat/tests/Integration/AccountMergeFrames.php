<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Hilos;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAskSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountOperationAnswerSignalData;
use Hilos\Auth\AccountOperation\DTO\AccountMergeHandoverSignalData;
use Hilos\Constants\CliCommands;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Users\AccountMergeProgressChangeSubscriber;
use Hilos\Users\AccountMergeProgressResolver;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountMergeCommandConstants;
use Hilos\Utils\Helpers\RandomHelper;

/** Real registered merge command and its finite personal/project owner exchange for Chat scenarios. */
trait AccountMergeFrames
{
    /**
     * @param int $survivorId Account receiving the loser's data
     * @param int $loserId Account folded into the survivor
     * @param ?PasswordFate $passwordFate Optional immutable password choice
     * @return CommandReplyDTO The command's one reply after its owners have recorded their work
     */
    private function runOwnerMerge(int $survivorId, int $loserId, ?PasswordFate $passwordFate = null): CommandReplyDTO
    {
        SourceChangeBus::subscribe(new AccountMergeProgressChangeSubscriber());
        AccountMergeProgressResolver::forgetAll();
        $chat = new ChatAgent();
        try {
            $this->startAgent($chat);
            $this->drainSignals();
            $library = $this->sessionsLibrary();
            $payload = [
                AccountMergeCommandConstants::FIELD_MODE => AccountMergeCommandConstants::MODE_START,
                AccountMergeCommandConstants::FIELD_SURVIVOR_USER_ID => $survivorId,
                AccountMergeCommandConstants::FIELD_LOSER_USER_ID => $loserId,
            ];
            if ($passwordFate !== null) {
                $payload[AccountMergeCommandConstants::FIELD_PASSWORD_FATE] = $passwordFate->value;
            }
            $this->underAgent($library, static fn () => $library->onSignalCommand(
                new CommandRequestDTO(RandomHelper::hex(8), CliCommands::ACCOUNT_MERGE, $payload), '', '',
            ));
            $replies = [];
            $carried = 0;
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                $this->assertLessThan(150, ++$carried, 'The finite owner exchange must settle.');
                $data = $signal->data;
                $name = $signal->signalName->getName();
                $sender = SignalSource::describe($signal->signalSource);
                if ($data instanceof AgentSignalData && $data->data instanceof AccountOperationAnswerSignalData) {
                    $this->underAgent($library, static fn () => $library->onSignalAgent($data, $sender, $name));
                } elseif ($data instanceof AgentSignalData && ($data->data instanceof AccountOperationAskSignalData
                    || $data->data instanceof AccountMergeHandoverSignalData)) {
                    $owner = $name === ChatAgent::ACCOUNT_OPERATION_SIGNAL
                        ? $chat : $this->personAgent((int)$data->data->ownerIndex);
                    $this->underAgent($owner, static fn () => $owner->onSignalAgent($data, $sender, $name));
                } elseif ($data instanceof CommandReplyDTO) {
                    $replies[] = $data;
                }
            }
            $this->assertCount(1, $replies, 'Every merge answers the operator exactly once');

            return $replies[0];
        } finally {
            TruthSourceRegistry::unregisterAgent($chat->getId());
            RtTruthSourceRegistry::unregisterAgent($chat->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($chat->getId()));
            AccountMergeProgressResolver::forgetAll();
        }
    }
}

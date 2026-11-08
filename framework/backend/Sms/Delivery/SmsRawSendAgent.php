<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\HilosException;
use Hilos\Sms\DTO\SmsSendSignalData;

/**
 * Indexed raw SMS sender with no durable delivery intake, recovery or database writes.

 */
class SmsRawSendAgent extends AbstractAgent
{
    use SmsSendPoolTrait;

    public const string AGENT_TYPE = HilosAgentType::HILOS_SMS;

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SMS_SEND => [
            AgentSignalConfigKey::INDEX_FIELD => SmsSendSignalData::shardKey,
            AgentSignalConfigKey::DTO => SmsSendSignalData::class,
        ],
    ];

    /** @var list<string> Settings overrides used to resolve the SMS transport configuration */
    public const array READS_DB = [HilosDbContext::settings];

    /**
     * @param string $agentIndex Pool shard index (1..SMS_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('SmsRawSendAgent requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * Accepts only the raw-send name with its typed payload; other frames are refused before transport work.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Sender identity (unused)
     * @param string $name Routed agent-signal name
     * @throws InvalidAgentSignalPayloadException When a raw-send frame carries another payload type
     * @throws AgentUnknownSignalException When another signal name reaches this worker
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SMS_SEND:
                $payload = $data->data;
                if (!$payload instanceof SmsSendSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SmsSendSignalData::class, $payload);
                }

                $this->enqueueRawSend($payload);

                return;

            default:
                throw new AgentUnknownSignalException($name);
        }
    }

    /**
     * Advances only the instance-local raw-send pool.
     *
     * @throws HilosException When an in-flight SMS transport cannot advance
     */
    public function onTick(): void
    {
        $this->tickRawSends(microtime(true) * TimeConstants::MS_PER_SECOND);
    }

    /** Releases raw transports; the worker performs the standard agent cleanup after this hook. */
    public function onStop(): void
    {
        $this->closeRawSends();
    }
}

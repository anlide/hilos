<?php

declare(strict_types=1);

namespace Hilos\Testing;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Database\Exception\OrmForgetRefusedException;
use Hilos\Database\OrmTestConstants;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/** Runs the existing test-only reset in each explicitly named process, without retry or fallback. */
class OrmTestResetter
{
    private const int CORRELATION_ID_BYTES = 16;

    /**
     * Attempts every valid target even if an earlier reset failed. Call only after rollback and handlerEnd,
     * while the affected context is still mounted; obtain row references again after this call.
     *
     * @param list<OrmResetTarget> $targets Processes whose memory was invalidated
     * @throws OrmForgetRefusedException When the list is invalid or any local/remote reset fails
     */
    public function reset(array $targets): void
    {
        if ($targets === [] || !array_is_list($targets)) {
            throw new OrmForgetRefusedException('ORM reset requires a nonempty list of targets');
        }
        foreach ($targets as $target) {
            if (!$target instanceof OrmResetTarget) {
                throw new OrmForgetRefusedException('Every ORM reset target must be an OrmResetTarget');
            }
        }

        $failures = [];
        foreach ($targets as $target) {
            try {
                $this->resetTarget($target);
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }
        if ($failures !== []) {
            throw new OrmForgetRefusedException(
                implode("\n", array_map(static fn (Throwable $failure): string => $failure->getMessage(), $failures)),
                previous: $failures[0],
            );
        }
    }

    /**
     * @param string $host Explicit node command host
     * @param int $port Command port
     * @return AsyncCommandClient A fresh client for exactly one request
     */
    protected function createClient(string $host, int $port): AsyncCommandClient
    {
        return new AsyncCommandClient($host, $port);
    }

    /** @return float Monotonic seconds for the bounded command wait */
    protected function nowSeconds(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }

    /** Yields between polls using the ordinary command-channel interval. */
    protected function waitForPoll(): void
    {
        usleep(CommandConstants::POLL_INTERVAL_US);
    }

    /**
     * @param OrmResetTarget $target One validated process address
     * @throws OrmForgetRefusedException When the context, transport, reply or admission refuses the reset
     */
    private function resetTarget(OrmResetTarget $target): void
    {
        if ($target->host === null || $target->port === null) {
            if (Hilos::$db === null) {
                throw new OrmForgetRefusedException(OrmTestConstants::NO_CONTEXT);
            }
            Hilos::$db->forgetLoadedDataForTest();

            return;
        }

        $client = $this->createClient($target->host, $target->port);
        try {
            $payload = [OrmTestConstants::FIELD_PROCESS => $target->process];
            if ($target->workerId !== null) {
                $payload[OrmTestConstants::FIELD_WORKER_ID] = $target->workerId;
            }
            $request = new CommandRequestDTO(RandomHelper::secureHex(self::CORRELATION_ID_BYTES), CliCommands::ORM_TEST_FORGET, $payload);
            $deadline = $this->nowSeconds() + CommandChannelWindows::CALLER_WAIT_SECONDS;
            $client->startRequest($request);
            while (!$client->hasResult()) {
                if ($this->nowSeconds() >= $deadline) {
                    throw new OrmForgetRefusedException('Timed out waiting for ' . CliCommands::ORM_TEST_FORGET);
                }
                $client->tick();
                $this->waitForPoll();
            }

            $reply = $client->consumeResult();
            if ($reply->correlationId !== $request->correlationId) {
                throw new OrmForgetRefusedException('Unexpected ORM reset reply correlation');
            }
            if (!$reply->isOk()) {
                $message = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? null;
                throw new OrmForgetRefusedException(is_string($message) ? $message : 'ORM reset was refused');
            }
            if (($reply->payload[OrmTestConstants::FIELD_PROCESS] ?? null) !== $target->process
                || !array_key_exists(OrmTestConstants::FIELD_WORKER_ID, $reply->payload)
                || $reply->payload[OrmTestConstants::FIELD_WORKER_ID] !== $target->workerId) {
                throw new OrmForgetRefusedException('Unexpected ORM reset reply target');
            }
        } catch (Throwable $failure) {
            throw new OrmForgetRefusedException($failure->getMessage(), previous: $failure);
        } finally {
            $client->reset();
        }
    }
}

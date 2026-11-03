<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;

final class AgentSignalPayloadLookAlikes
{
    public function unrelated(AgentSignalData $message): void
    {
        $this->receive($message->data);
    }

    public function onSignalAgent(AgentSignalData $message, string $sender, string $name): void
    {
        switch ($name) {
            case 'direct-guard':
                if (!$message->data instanceof SignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $message->data);
                }
                $this->receive($message->data);
                return;

            case 'alias-guard':
                $inner = $message->data;
                if (!$inner instanceof SignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $inner);
                }
                $this->receive($sender, $inner);
                return;

            case 'shared-first':
            case 'shared-second':
                $payload = $message->data;
                if ($payload instanceof SignalData) {
                    $this->receive($payload);
                }
                return;

            case 'guard-before-alias':
                if (!$message->data instanceof SignalData) {
                    return;
                }
                $copy = $message->data;
                $this->receive($copy);
                return;

            case 'look-alikes':
                // receive($message->data);
                $literal = 'receive($message->data)';
                $interpolated = "receive({$message->data})";
                $example = <<<'PHP'
                function onSignalAgent(AgentSignalData $message) {
                    switch ($name) { case 'example': receive($message->data); }
                }
                PHP;
                $this->receive($literal, $interpolated, $example);
                return;

            case 'no-whole-payload-call':
                $this->receive($message);
                $this->receive($message->data->field);
                $this->receive([$sender, $message->data, $name]);
                return;

            case 'nested-functions':
                $closure = function () use ($message): void {
                    $this->receive($message->data);
                };
                $arrow = fn() => $this->receive($message->data);
                $this->receive($closure, $arrow);
                return;
        }
    }
}

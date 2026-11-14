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
        if ($name === 'before-switch') {
            $answer = $message->data;
            if (!$answer instanceof SignalData) {
                throw new InvalidAgentSignalPayloadException($name, SignalData::class, $answer);
            }
            $this->receive($answer);
            return;
        }

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
                if (!$payload instanceof SignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $payload);
                }
                $this->receive($payload);
                return;

            case 'guard-before-alias':
                if (!$message->data instanceof SignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $message->data);
                }
                $copy = $message->data;
                $this->receive($copy);
                return;

            case 'positive-guard':
                $reply = $message->data;
                if ($reply instanceof SignalData) {
                    $this->receive($reply);
                } else {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $reply);
                }
                return;

            case 'positive-chain':
                if ($message->data instanceof SignalData) {
                    $this->receive($message->data);
                } elseif ($message->data instanceof AgentSignalData) {
                    $this->receive($message->data);
                } else {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $message->data);
                }
                return;

            case 'multiline-refusal':
                $reply = $message->data;
                if (!$reply instanceof SignalData) {
                    // A comment is not the first statement of the branch.
                    throw new InvalidAgentSignalPayloadException(
                        $name,
                        SignalData::class,
                        $reply,
                    );
                }
                $this->receive($reply);
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

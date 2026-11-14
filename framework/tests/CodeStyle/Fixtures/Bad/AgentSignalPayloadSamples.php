<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalData;

final class AgentSignalPayloadSamples
{
    public function unrelated(AgentSignalData $packet): void
    {
        if ($packet->data instanceof SignalData) {
            return;
        }
    }

    public function onSignalAgent(AgentSignalData $packet, string $sender, string $name): void
    {
        // Outside the switch: a positive check that hands any other payload on, and a negated one that logs and leaves.
        if ($packet->data instanceof SignalData) {
            $this->receive($packet->data);
            return;
        }
        if (!$packet->data instanceof SignalData) {
            $this->logAgentWarning($name);
            return;
        }

        switch ($name) {
            case 'direct':
                $this->receive($packet->data);
                return;

            case 'alias':
                $payload = $packet->data;
                $this->receive($sender, $payload);
                return;

            case 'guarded-neighbor':
                if ($packet->data instanceof SignalData) {
                    $this->receive($packet->data);
                }
                return;

            case 'foreign-case':
                $this->receive($packet->data);
                return;

            case 'foreign-value':
                $payload = $packet->data;
                if ($sender instanceof SignalData) {
                    return;
                }
                $this->receive($payload);
                return;

            case 'group-one':
            case 'group-two':
                $this->receive($packet->data);
                return;

            case 'comment-and-string':
                // $packet->data instanceof SignalData
                $example = '$packet->data instanceof SignalData';
                $this->receive($packet->data, $example);
                return;

            case 'closure':
                $check = function () use ($packet): bool {
                    return $packet->data instanceof SignalData;
                };
                $this->receive($packet->data, $check);
                return;

            case 'arrow':
                $check = fn(): bool => $packet->data instanceof SignalData;
                $this->receive($packet->data, $check);
                return;

            case 'late-guard':
                $this->receive($packet->data);
                if ($packet->data instanceof SignalData) {
                    return;
                }
                return;

            case 'alias-neighbor-guard':
                $payload = $packet->data;
                if ($payload instanceof SignalData) {
                    return;
                }
                return;

            case 'alias-neighbor-use':
                $payload = $packet->data;
                $this->receive($payload);
                return;

            case 'reassigned-alias':
                $payload = new SignalData([]);
                if ($payload instanceof SignalData) {
                    $payload = $packet->data;
                    $this->receive($payload);
                }
                return;

            case 'other-exception':
                if (!$packet->data instanceof SignalData) {
                    throw new LogicException($name);
                }
                return;

            case 'silent-return':
                $payload = $packet->data;
                if (!$payload instanceof SignalData) {
                    return;
                }
                $this->receive($payload);
                return;

            case 'logged-return':
                if (!$packet->data instanceof SignalData) {
                    $this->logAgentWarning($name);
                    return;
                }
                return;

            case 'chain-without-else':
                if ($packet->data instanceof SignalData) {
                    $this->receive($packet->data);
                } elseif ($sender === $name) {
                    return;
                }
                return;

            case 'else-other-exception':
                $payload = $packet->data;
                if ($payload instanceof SignalData) {
                    $this->receive($payload);
                } else {
                    throw new LogicException($name);
                }
                return;

            case 'negated-group':
                if (!($packet->data instanceof SignalData)) {
                    throw new InvalidAgentSignalPayloadException($name, SignalData::class, $packet->data);
                }
                return;

            case 'outside-a-condition':
                $kind = $packet->data instanceof SignalData ? $name : $sender;
                $this->receive($kind);
                return;

            case 'nested-switch':
                switch ($sender) {
                    case 'inner':
                        if (!$packet->data instanceof SignalData) {
                            return;
                        }
                }
                return;
        }
    }
}

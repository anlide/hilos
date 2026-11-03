<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

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
        }
    }
}

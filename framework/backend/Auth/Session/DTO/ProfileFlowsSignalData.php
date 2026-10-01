<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\View\Item\HilosProfileFlow;

/**
 * Sessions library → every tab of one session: these are the profile windows it is half-way
 * through (HIL-1182).
 *
 * The frame carries the LIST and not the change, the shape {@see SessionToastsSignalData}
 * established: a reload, a second tab and an ordinary step are then one and the same sentence,
 * and a tab that has just come back needs nothing but this frame to open its window on the right
 * step. An EMPTY list is a legal frame and the ordinary way a finished or discarded flow closes
 * the windows of the other tabs.
 *
 * What an entry does NOT carry is the person it belongs to and the moment its code dies. The first
 * is the session's own, and the second is the server's to judge by: a tab has nothing to do with
 * either, and a flow whose code is dead is left out of the list altogether.
 */
final class ProfileFlowsSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param list<array{operation: string, step: string, address: string, target: ?string}> $flows
     *     Every live flow of the session, at most one per window
     */
    public function __construct(
        public readonly array $flows,
    ) {
    }

    /**
     * Builds the frame for what one session is half-way through, which may be nothing.
     *
     * The one place a stored flow is turned into a shown one, which is why a dead one is left out
     * here rather than at each call site: the tick takes it away without a frame, and a frame sent
     * before that must not open a window on a step whose code nobody can enter any more.
     *
     * @param list<HilosProfileFlow> $flows Flows of the session
     * @param int $nowMs Epoch milliseconds to judge the codes by
     * @return self Frame carrying every live flow
     */
    public static function fromFlows(array $flows, int $nowMs): self
    {
        $shown = [];
        foreach ($flows as $flow) {
            if ($flow->expiresAt <= $nowMs) {
                continue;
            }

            $shown[] = [
                StateHilosProfileFlow::operation => $flow->operation,
                StateHilosProfileFlow::step => $flow->step,
                StateHilosProfileFlow::address => $flow->address,
                StateHilosProfileFlow::target => $flow->target,
            ];
        }

        return new self($shown);
    }

    /**
     * @return array<string, mixed> DTO payload for transport
     */
    public function toArray(): array
    {
        return [
            'flows' => $this->flows,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no list at all
     */
    public static function fromArray(array $data): static
    {
        /** @var list<array{operation: string, step: string, address: string, target: ?string}> $flows */
        $flows = array_values(self::requireArray($data, 'flows'));

        return new static($flows);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Auth\Session\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Item\HilosSessionToastStack;

/**
 * Sessions library → every tab of one session: this is the whole stack now (HIL-768).
 *
 * The frame carries the LIST and not the change. A reconnect, a second tab, a reload and an
 * ordinary removal are then one and the same sentence, and the tab that has just come back
 * needs nothing but this frame to be right; a delta would have to be chased with a catch-up
 * request for freshness, which is the shape this project does not use.
 *
 * An EMPTY list is a legal frame and the ordinary way a card leaves the screen: it is what the
 * server says when the last one is gone.
 *
 * What each entry does NOT carry is who has expired it and who is holding it. Those are the
 * server's own workings; a browser only ever answers for itself, and the answer it needs to
 * give is keyed by {@see StateHilosSessionToastStack::TOAST_KEY}.
 */
final class SessionToastsSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param list<array{key: string, message: string, severity: string, source: string,
     *     destination: string, repeats: int}> $toasts Whole stack the session is being shown
     */
    public function __construct(
        public readonly array $toasts,
    ) {
    }

    /**
     * Builds the frame for what one session is being shown, which may be nothing.
     *
     * The one place a stored card is turned into a shown one, which is why the null row is
     * handled here rather than at each call site: a session whose row has just been taken away
     * is being shown an empty stack, and that has to reach the tabs like any other state.
     *
     * @param ?HilosSessionToastStack $stack Session's stack, or null when it has none
     * @return self Frame carrying the whole stack
     */
    public static function fromStack(?HilosSessionToastStack $stack): self
    {
        $toasts = [];
        foreach ($stack?->toasts ?? [] as $toast) {
            $toasts[] = [
                StateHilosSessionToastStack::TOAST_KEY => $toast[StateHilosSessionToastStack::TOAST_KEY],
                StateHilosSessionToastStack::TOAST_MESSAGE => $toast[StateHilosSessionToastStack::TOAST_MESSAGE],
                StateHilosSessionToastStack::TOAST_SEVERITY => $toast[StateHilosSessionToastStack::TOAST_SEVERITY],
                StateHilosSessionToastStack::TOAST_SOURCE => $toast[StateHilosSessionToastStack::TOAST_SOURCE],
                StateHilosSessionToastStack::TOAST_DESTINATION
                    => $toast[StateHilosSessionToastStack::TOAST_DESTINATION],
                StateHilosSessionToastStack::TOAST_REPEATS => $toast[StateHilosSessionToastStack::TOAST_REPEATS],
            ];
        }

        return new self($toasts);
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'toasts' => $this->toasts,
        ];
    }

    /**
     * Create DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no stack at all
     */
    public static function fromArray(array $data): static
    {
        /** @var list<array{key: string, message: string, severity: string, source: string,
         *     destination: string, repeats: int}> $toasts */
        $toasts = array_values(self::requireArray($data, 'toasts'));

        return new static($toasts);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Hilos\Core\Router\PageSubscription;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;

/** Exact router and immutable subscription selected before an old-owner continuation can yield. */
final readonly class PageSubscriptionContext
{
    /**
     * @param SignalRouter $router Original routing incarnation
     * @param string $acceptKey Original connection key
     * @param ?PageSubscription $subscription Original subscription, absent before its first subscribe
     * @param ?Closure(PageSubscription): bool $answerAdmission Captured identity of a source answer
     */
    public function __construct(
        public SignalRouter $router,
        public string $acceptKey,
        public ?PageSubscription $subscription,
        private ?Closure $answerAdmission = null,
    ) {
    }

    /** @return bool Whether this exact original record still belongs to the active routing incarnation */
    public function isCurrent(): bool
    {
        return Hilos::$sr === $this->router && $this->router->pageSubscription($this->acceptKey) === $this->subscription;
    }

    /** @return bool Whether a queued fan-out frame still belongs to its original admitted subscription */
    public function admitsQueuedSignal(): bool
    {
        return $this->isCurrent() && $this->subscription?->admitsPageAnswer($this->acceptKey) === true && $this->isCurrent();
    }

    /**
     * @return self Exact assembly address with the identity evidence used through delivery
     */
    public function forFanOutAnswer(): self
    {
        $acceptKey = $this->acceptKey;
        $proof = $this->subscription?->admittedIdentity;
        if ($this->subscription?->deliveryId !== null) {
            $admission = static fn (PageSubscription $current): bool => $current->admittedIdentity === $proof;
        } else {
            $session = PageHandoffIdentity::current($acceptKey);
            $identity = $session === null ? Hilos::$browser?->connectionIdentity($acceptKey) : null;
            $admission = static fn (PageSubscription $current): bool => $current->admittedIdentity === $proof
                && $session == PageHandoffIdentity::current($acceptKey)
                && ($session !== null || $identity == Hilos::$browser?->connectionIdentity($acceptKey));
        }

        return new self($this->router, $acceptKey, $this->subscription, $admission);
    }

    /**
     * @return ?PageSubscription Admitted address of an accepted answer, including a same-address ordinary bind
     */
    public function currentAnswerRegistration(): ?PageSubscription
    {
        if (Hilos::$sr !== $this->router || $this->subscription === null) {
            return null;
        }
        $current = $this->router->pageSubscription($this->acceptKey);
        if ($current === null || !$this->subscription->isSameServingRegistration($current)
            || !$current->admitsPageAnswer($this->acceptKey)
            || ($this->answerAdmission !== null && !($this->answerAdmission)($current))
            || Hilos::$sr !== $this->router || $this->router->pageSubscription($this->acceptKey) !== $current
        ) {
            return null;
        }

        return $current;
    }

    /** @return bool Whether synchronous assembly still has the exact subscription and admitted identity */
    public function admitsOriginalAnswer(): bool
    {
        return $this->subscription !== null && $this->isCurrent() && $this->currentAnswerRegistration() === $this->subscription;
    }

    /** @return bool Whether the queued whole answer or refusal still has its admitted address */
    public function admitsQueuedAnswer(): bool
    {
        return $this->currentAnswerRegistration() !== null;
    }
}

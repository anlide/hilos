<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Core\Exception\LogicException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime;

/** Queue-time admission values, prepared once before a local notification visits its transports. */
final class ProtectedModeAdmission
{
    public private(set) bool $ready = false;

    private readonly StateProtectedModeRuntime $policy;

    /** @var list<string> Original pass admissions; sharing these lists still has a writer-side COW cost */
    private array $admitted;

    /** @var list<string> Original circle admissions */
    private array $circle;

    /** @var array<string, string> Original pass hashes, indexed without changing their comparison value */
    private array $admittedByHash = [];

    /** @var array<string, string> Original circle hashes */
    private array $circleByHash = [];

    /** @param ProtectedModeRuntime $snapshot Detached original rule, without a live runtime owner */
    public function __construct(ProtectedModeRuntime $snapshot)
    {
        $this->policy = StateProtectedModeRuntime::create();
        $this->policy->phase = $snapshot->phase;
        $this->policy->initiatorAcceptKey = $snapshot->initiatorAcceptKey;
        $this->policy->initiatorSessionTokenHash = $snapshot->initiatorSessionTokenHash;
        $this->admitted = $snapshot->admittedSessionTokenHashes;
        $this->circle = $snapshot->circleSessionTokenHashes;
    }

    /**
     * Each visit costs that hash's bytes and PHP index growth. The input lists retain their
     * original queue-time values; this does not eliminate COW or whole-index retirement costs.
     *
     * @return Generator<int, void> Effect-free admission, then one original hash per preparation step
     */
    public function build(): Generator
    {
        if ($this->ready) {
            return;
        }
        yield;
        foreach ($this->admitted as $hash) {
            $this->admittedByHash[$hash] = $hash;
            yield;
        }
        foreach ($this->circle as $hash) {
            $this->circleByHash[$hash] = $hash;
            yield;
        }
        $this->admitted = [];
        $this->circle = [];
        $this->ready = true;
    }

    /**
     * The canonical policy still decides every phase and identity. Its admission scans receive
     * at most one original candidate from each index and retain their hash_equals comparison.
     *
     * @param ?string $acceptKey Original connection's accept key
     * @param ?string $sessionTokenHash Session hash it carries at its guarded visit
     * @return bool Whether the captured rule locks this connection out
     * @throws LogicException When a caller visits a target before completing preparation
     */
    public function locksOut(?string $acceptKey, ?string $sessionTokenHash): bool
    {
        if (!$this->ready) {
            throw new LogicException('Protected-mode admission preparation is incomplete');
        }
        $this->policy->admittedSessionTokenHashes = $sessionTokenHash !== null && isset($this->admittedByHash[$sessionTokenHash])
            ? [$this->admittedByHash[$sessionTokenHash]] : [];
        $this->policy->circleSessionTokenHashes = $sessionTokenHash !== null && isset($this->circleByHash[$sessionTokenHash])
            ? [$this->circleByHash[$sessionTokenHash]] : [];
        return $this->policy->locksOut($acceptKey, $sessionTokenHash);
    }
}

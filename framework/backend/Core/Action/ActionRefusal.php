<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Throwable;

/**
 * Why a writer refused an ask, in the three fields an ack of a failed action carries (HIL-1001).
 *
 * A writer refuses in one of three ways, and all three keep their words. A sentence it composed
 * with no exception behind it travels as it is. A failure written for the person who asked travels
 * whole too, exactly as {@see ActionReply::sendFailure()} lets it: a detail beside it would only
 * repeat it. Anything else - a driver fault, an unexpected engine error - becomes the placeholder,
 * and the class of the failure and its own text ride beside it for an administrator to quote.
 * Those two are what was lost while each writer reduced its failure to a string before handing it
 * back; whether they reach the client is still the gatekeeper's call, not the writer's.
 *
 * Logging stays with the writer, because the context sentence is its own. What it is given here is
 * the question to log on: {@see isInternal()}. The refusal travels back to the gatekeeper inside
 * {@see HandoverAnswerSignalData::to()}.
 */
final readonly class ActionRefusal
{
    /**
     * @param string $reason What the person reads
     * @param ?string $errorType Class name of the failure the reason stands for, or null when nothing was held back
     * @param ?string $errorDetail Original message of that failure, or null when nothing was held back
     */
    private function __construct(
        public string $reason,
        public ?string $errorType,
        public ?string $errorDetail,
    ) {
    }

    /**
     * Refuses in a sentence the writer composed, with no failure behind it to name.
     *
     * @param string $reason Sentence addressed to the person who asked
     * @return self Refusal carrying the sentence alone
     */
    public static function said(string $reason): self
    {
        return new self($reason, null, null);
    }

    /**
     * Refuses with a failure the write raised, through the one door on what a client may read.
     *
     * @param Throwable $e Failure raised by the write
     * @return self Refusal carrying the failure's own text, or the placeholder with the failure beside it
     */
    public static function fromThrowable(Throwable $e): self
    {
        if (ActionFailureReason::isPersonFacing($e)) {
            return new self($e->getMessage(), null, null);
        }

        return new self(SignalConstants::ACTION_FAILED_REASON, ActionFailureReason::typeOf($e), $e->getMessage());
    }

    /**
     * Tells whether the reason stands in for a failure the person was not written to.
     *
     * @return bool Whether the refusal holds back a detail, which is the refusal a writer logs
     */
    public function isInternal(): bool
    {
        return $this->errorDetail !== null;
    }
}

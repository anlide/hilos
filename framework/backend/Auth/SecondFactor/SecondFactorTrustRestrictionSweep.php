<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Closure;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AgentId;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Daemon\Cron\CronRule;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictDoneSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use Throwable;

/**
 * SecondFactorTrustRestrictionSweep - brings the stored trust restrictions to the trusts of people
 * whose agents sleep (HIL-1901).
 *
 * Run by the sessions holder ({@see AbstractSessionsLibraryAgent}) from its cron rule once a minute.
 * It writes nothing: each person who still holds a trust a restriction breaks is sent the restriction
 * frame, and the person's agent ({@see AbstractUserAgent}) applies every stored restriction and
 * answers. A pass walks those people by id, a portion of frames at a time, and ends once nobody is
 * left to ask and every frame is answered, dropped after a minute of silence or dropped because
 * the agent is gone. A new pass starts an hour after the last one ended; a request to bring one
 * restriction home starts it at once, or winds the running pass back to its start.
 *
 * Everything it keeps is in this object and dies with the holder: no cursor survives a restart, and a
 * new holder finds what is left in the data on its first tick. A frame sent twice is harmless - the
 * agent finds nothing left to change and answers ok.
 */
final class SecondFactorTrustRestrictionSweep
{
    /** Most frames of one pass awaiting their answer at a time. */
    public const int PORTION = 100;

    /** How long an unanswered frame is waited for before its person is dropped from the pass. */
    public const int SILENCE_MS = TimeConstants::SECONDS_PER_MINUTE * TimeConstants::MS_PER_SECOND;

    /** How long after a pass ended the schedule starts the next one by itself. */
    public const int RECOVERY_PAUSE_MS = TimeConstants::SECONDS_PER_HOUR * TimeConstants::MS_PER_SECOND;

    /** Id of the last person sent a frame in this pass, or null when no pass is running. */
    private ?int $position = null;

    /** Whether the selection of this pass has come to its end. */
    private bool $exhausted = false;

    /** @var array<int, int> Moment each frame of the pass still unanswered was sent (epoch milliseconds), by person */
    private array $inFlight = [];

    /** @var array<int, list<Closure(int): void>> Callbacks waiting for the end of the pass, by the restriction each waits for */
    private array $waiters = [];

    /** Receipt of the scheduled attempt this pass runs under, or null until a tick of it sends a frame. */
    private ?int $receiptId = null;

    /** Moment the last pass ended (epoch milliseconds), or null when this holder has not ended one. */
    private ?int $lastPassEndedAt = null;

    /**
     * @param AbstractSessionsLibraryAgent $holder The sessions holder whose schedule runs the sweep, and which sends the frames
     * @param int $portion Most frames of one pass awaiting their answer at a time
     */
    public function __construct(
        private readonly AbstractSessionsLibraryAgent $holder,
        private readonly int $portion = self::PORTION,
    ) {
    }

    /**
     * One tick of the schedule.
     *
     * Drops the frames unanswered for a minute, then ends the pass that has nothing left. With no
     * pass running a new one starts only when none ended within the hour; a quiet minute reads
     * nothing. The portion it sends runs under the receipt of the scheduled attempt, opened by the
     * first tick of the pass that has somebody to ask.
     *
     * @param int $nowMs Moment of the tick (epoch milliseconds)
     * @param CronRule $rule Rule whose schedule fired
     * @throws HilosException When a lookup, a frame or the receipt fails
     */
    public function cron(int $nowMs, CronRule $rule): void
    {
        foreach ($this->inFlight as $userId => $sentAt) {
            if ($nowMs - $sentAt >= self::SILENCE_MS) {
                unset($this->inFlight[$userId]);
                $this->logHolderWarning("Trust restriction not confirmed by #{$userId} within a minute");
            }
        }
        if ($this->passDone()) {
            $this->endPass($nowMs);
        }
        if ($this->position === null) {
            if ($this->lastPassEndedAt !== null && $nowMs - $this->lastPassEndedAt < self::RECOVERY_PAUSE_MS) {
                return;
            }
            $this->startPass();
        }

        $this->sendPortion($nowMs, $rule);
        if ($this->passDone()) {
            $this->endPass($nowMs);
        }
    }

    /**
     * Asks for one restriction to be brought to every trust it breaks, and calls back once the
     * pass that does it ends.
     *
     * With no pass running the pass starts now and sends its first portion in the caller's handler,
     * so the frames carry the caller's receipt; the hour between passes does not hold a request. A
     * running pass is wound back to its start, and its next portion goes on the next tick; nobody it
     * is waiting for is asked twice. When nothing is owed the callback runs before this returns.
     * A request whose first portion fails stays registered and is answered when the pass ends: the
     * failure reaches the caller, and the next tick goes on after it. The holder that stops first
     * drops the request unanswered.
     *
     * SCAFFOLD: not wired yet — the hand-overs of the trust term and of switching the second factor
     * off are moved onto this request by HIL-1908 and HIL-1909; until then only tests call it.
     *
     * @param int $restrictionId Restriction to bring home, positive
     * @param Closure(int): void $settled Called with the number of trusts the restrictions up to this
     *     one still break when the pass ends; 0 means it is brought home
     * @throws InvalidArgumentException When the restriction id is not positive
     * @throws HilosException When a lookup or a frame fails
     */
    public function await(int $restrictionId, Closure $settled): void
    {
        if ($restrictionId <= 0) {
            throw new InvalidArgumentException("A trust restriction id must be positive, {$restrictionId} given");
        }

        $this->waiters[$restrictionId][] = $settled;
        if ($this->position !== null) {
            $this->startPass();

            return;
        }

        $nowMs = TimeHelper::nowMs();
        $this->startPass();
        $this->sendPortion($nowMs, null);
        if ($this->passDone()) {
            $this->endPass($nowMs);
        }
    }

    /**
     * Takes a person's agent's answer to a frame of the pass.
     *
     * An answer from somebody the pass is not waiting for - late after a minute, repeated, or of an
     * earlier pass - is passed over. A refusal is logged and its person is not asked again in this
     * pass.
     *
     * @param UserSecondFactorTrustRestrictDoneSignalData $done The answer, carrying the request back whole
     * @throws HilosException When the end of the pass cannot count what is owed or close its receipt
     */
    public function answered(UserSecondFactorTrustRestrictDoneSignalData $done): void
    {
        $userId = $done->request->userId;
        if (!isset($this->inFlight[$userId])) {
            return;
        }

        unset($this->inFlight[$userId]);
        if ($done->error !== null) {
            $this->logHolderWarning(
                "Trust restriction {$done->request->restrictionId} for #{$userId} refused: " . ($done->errorDetail ?? $done->error),
            );
        }
        if ($this->passDone()) {
            $this->endPass(TimeHelper::nowMs());
        }
    }

    /**
     * Drops at once the people whose agents are gone, instead of waiting a minute for them.
     *
     * @param AgentsGoneSignalData $gone Agents that ended or never started, and why
     * @throws HilosException When the end of the pass cannot count what is owed or close its receipt
     */
    public function gone(AgentsGoneSignalData $gone): void
    {
        foreach ($gone->agentIds as $agentId) {
            $agent = AgentId::fromId($agentId);
            if ($agent->type !== HilosAgentType::HILOS_USER || $agent->index === null) {
                continue;
            }
            $userId = (int)$agent->index;
            if (!isset($this->inFlight[$userId])) {
                continue;
            }

            unset($this->inFlight[$userId]);
            $this->logHolderWarning("Trust restriction not confirmed by #{$userId}, its agent gone: {$gone->reason}");
        }
        if ($this->passDone()) {
            $this->endPass(TimeHelper::nowMs());
        }
    }

    /**
     * Closes the receipt of the running pass and forgets the pass with its requests, as the holder
     * stops: whoever asked gets no answer, as when the holder falls.
     *
     * @throws DatabaseException When the receipt cannot be closed
     */
    public function stop(): void
    {
        $receiptId = $this->receiptId;
        $this->receiptId = null;
        $this->position = null;
        $this->exhausted = false;
        $this->inFlight = [];
        $this->waiters = [];
        JournalReceiptScope::finish($receiptId);
    }

    /** Starts a pass from the first person, or winds the running one back there. */
    private function startPass(): void
    {
        $this->position = 0;
        $this->exhausted = false;
    }

    /** @return bool Whether the running pass has nobody left to ask and no frame left to wait for */
    private function passDone(): bool
    {
        return $this->position !== null && $this->exhausted && $this->inFlight === [];
    }

    /**
     * Sends the restriction frame to the next people who owe it, up to the free room of the portion.
     *
     * The selection is read fresh: the people's agents write the trusts and the settings library the
     * restrictions, each in its own process. A failure leaves the tick; whatever was sent before it
     * stays awaited, and the next tick goes on after it.
     *
     * @param int $nowMs Moment the frames are sent (epoch milliseconds)
     * @param ?CronRule $rule Rule of the tick whose receipt the frames carry, or null to send them under the caller's
     * @throws HilosException When a lookup, a frame or the receipt fails
     */
    private function sendPortion(int $nowMs, ?CronRule $rule): void
    {
        $room = $this->portion - count($this->inFlight);
        if ($this->exhausted || $room <= 0) {
            return;
        }

        $latest = Hilos::$db->secondFactorTrustRestrictions->getObjectCollection()->latestId();
        if ($latest === null) {
            $this->exhausted = true;

            return;
        }
        $people = Hilos::$db->secondFactorTrusts->getObjectCollection()->owingPeople(
            $this->position ?? 0,
            array_keys($this->inFlight),
            $room,
        );
        if ($people !== []) {
            $send = function () use ($people, $latest, $nowMs): void {
                foreach ($people as $userId) {
                    $this->holder->sendToAgent(
                        HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT,
                        new UserSecondFactorTrustRestrictSignalData(
                            $userId,
                            $latest,
                            HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE,
                        ),
                    );
                    $this->inFlight[$userId] = $nowMs;
                    $this->position = $userId;
                }
            };
            if ($rule === null) {
                $send();
            } else {
                $this->receiptId ??= JournalReceiptScope::start(
                    JournalReceiptData::cron($rule->name, $rule->expression, $this->holder->getId()),
                );
                JournalReceiptScope::runPart($this->receiptId, $this->holder->getId(), $send);
            }
        }
        $this->exhausted = count($people) < $room;
    }

    /**
     * Ends the pass: counts for every request what is still owed, forgets the pass, answers the
     * requests and closes the receipt.
     *
     * The counts are read before anything is forgotten, so a failed read leaves the pass to be ended
     * by the next tick. The pass is forgotten before any callback runs, so a callback may ask again.
     * A callback that throws is logged, and the others are answered all the same.
     *
     * @param int $nowMs Moment the pass ends (epoch milliseconds)
     * @throws HilosException When a count fails or the receipt cannot be closed
     */
    private function endPass(int $nowMs): void
    {
        $trusts = Hilos::$db->secondFactorTrusts->getObjectCollection();
        $owing = [];
        foreach (array_keys($this->waiters) as $restrictionId) {
            $owing[$restrictionId] = $trusts->countOwing($restrictionId);
        }
        $waiters = $this->waiters;
        $receiptId = $this->receiptId;
        $this->waiters = [];
        $this->receiptId = null;
        $this->position = null;
        $this->exhausted = false;
        $this->lastPassEndedAt = $nowMs;

        try {
            foreach ($waiters as $restrictionId => $callbacks) {
                foreach ($callbacks as $settled) {
                    try {
                        $settled($owing[$restrictionId]);
                    } catch (Throwable $failure) {
                        $this->logHolderWarning(
                            "Trust restriction {$restrictionId} could not be reported to whoever waited: {$failure->getMessage()}",
                        );
                    }
                }
            }
        } finally {
            JournalReceiptScope::finish($receiptId);
        }
    }

    /** @param string $message Line written to the holder's log as a warning */
    private function logHolderWarning(string $message): void
    {
        Logger::logAgentWarning($this->holder->getId(), $message);
    }
}

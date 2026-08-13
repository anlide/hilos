<?php

declare(strict_types=1);

namespace Hilos\Auth\Flow;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * AuthFlowOutcome - what an auth submit answers the surface with (HIL-413 contract).
 *
 * The backend half of `AuthFlowSubmitOutcome` in `@hilos/core`
 * (`auth/authFlow.ts`): the flow machine does not decide where a submit goes, the
 * backend does, and this is how it says so. It rides the action's success ack as
 * the domain reply ({@see ActionReplyDTO}), so no new signal or correlation is
 * introduced.
 *
 * A FAILURE rides the same ack rather than the action-error channel, which is the
 * one thing to understand here: `ok = false` still carries a `next`, because a
 * rejected submit on this surface usually MOVES - a taken address is answered with
 * the identifier step under the login intent, an expired hold with the identifier
 * step under register. An error signal cannot say that; it can only say no. The
 * codes are semantic, and the frontend maps them to whatever the view shows;
 * `message` is the backend's own sentence, because Hilos i18n is backend-side.
 *
 * Every leaf that answers this surface uses THIS type - the registration
 * reservation (HIL-415), the magic link (HIL-417), phone registration (HIL-486) -
 * so a second converge contract cannot appear by accident.
 */
final class AuthFlowOutcome extends ActionReplyDTO
{
    /** The submitted identifier already belongs to a live account: register turns into sign-in. */
    public const string CODE_IDENTIFIER_TAKEN = 'identifier_taken';

    /** The registration hold on the identifier ran out: the surface rolls back to the identifier step. */
    public const string CODE_RESERVATION_EXPIRED = 'reservation_expired';

    /** Wire key for the success flag. */
    private const string FIELD_OK = 'ok';

    /** Wire key for the backend-authored inline message. */
    private const string FIELD_MESSAGE = 'message';

    /** Wire key for the semantic error code. */
    private const string FIELD_CODE = 'code';

    /** Wire key for the partial next flow state. */
    private const string FIELD_NEXT = 'next';

    /** Wire key for the step inside {@see self::FIELD_NEXT}. */
    private const string FIELD_STEP = 'step';

    /** Wire key for the intent inside {@see self::FIELD_NEXT}. */
    private const string FIELD_INTENT = 'intent';

    /** Wire key for the resend gate in seconds. */
    private const string FIELD_RESEND_IN_SECONDS = 'resendInSeconds';

    /**
     * @param bool $ok Whether the submit succeeded
     * @param ?string $step Step the surface moves to, or null to stay put
     * @param ?string $intent Intent the surface moves to, or null to keep the current one
     * @param ?string $code Semantic error code on failure, or null
     * @param ?string $message Backend-authored inline message on failure, or null
     * @param ?int $resendInSeconds Seconds until a code re-send is allowed, or null when nothing was sent
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $step,
        public readonly ?string $intent,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?int $resendInSeconds,
    ) {
    }

    /**
     * Builds a successful outcome that moves the surface to a step.
     *
     * @param string $step Step the surface moves to (see AuthFlowStep)
     * @param string $intent Intent the surface moves under (see AuthFlowIntent)
     * @param ?int $resendInSeconds Seconds until a re-send is allowed, or null when no code is in play
     * @return static Success outcome
     */
    public static function moveTo(string $step, string $intent, ?int $resendInSeconds = null): static
    {
        return new static(true, $step, $intent, null, null, $resendInSeconds);
    }

    /**
     * Builds a failed outcome that still moves the surface to a step.
     *
     * The shape auth failures take here: the submit did not do what was asked, but
     * the surface knows where the person should be instead, so it says both.
     *
     * @param string $code Semantic error code (see self::CODE_*)
     * @param string $step Step the surface rolls to (see AuthFlowStep)
     * @param string $intent Intent the surface rolls to (see AuthFlowIntent)
     * @param ?string $message Backend-authored inline sentence, or null to let the view word it
     * @return static Failure outcome
     */
    public static function rejectTo(string $code, string $step, string $intent, ?string $message = null): static
    {
        return new static(false, $step, $intent, $code, $message, null);
    }

    /**
     * @return array<string, mixed> Wire form; every optional slot is omitted when absent
     */
    public function toArray(): array
    {
        $data = [self::FIELD_OK => $this->ok];
        if ($this->step !== null || $this->intent !== null) {
            $next = [];
            if ($this->step !== null) {
                $next[self::FIELD_STEP] = $this->step;
            }
            if ($this->intent !== null) {
                $next[self::FIELD_INTENT] = $this->intent;
            }
            $data[self::FIELD_NEXT] = $next;
        }
        if ($this->code !== null) {
            $data[self::FIELD_CODE] = $this->code;
        }
        if ($this->message !== null) {
            $data[self::FIELD_MESSAGE] = $this->message;
        }
        if ($this->resendInSeconds !== null) {
            $data[self::FIELD_RESEND_IN_SECONDS] = $this->resendInSeconds;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data Wire form of an outcome
     * @return static Restored outcome
     * @throws InvalidFormatException When the success flag is missing or an optional field is of another type
     */
    public static function fromArray(array $data): static
    {
        $next = self::optionalArray($data, self::FIELD_NEXT) ?? [];

        return new static(
            (bool)($data[self::FIELD_OK] ?? throw new InvalidFormatException('Auth flow outcome requires ' . self::FIELD_OK)),
            self::optionalString($next, self::FIELD_STEP),
            self::optionalString($next, self::FIELD_INTENT),
            self::optionalString($data, self::FIELD_CODE),
            self::optionalString($data, self::FIELD_MESSAGE),
            self::optionalInt($data, self::FIELD_RESEND_IN_SECONDS),
        );
    }
}

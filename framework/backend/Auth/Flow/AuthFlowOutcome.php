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

    /**
     * The clicked sign-in link is not good any more - wrong, expired, or already
     * used (HIL-417). Its own code rather than the generic refusal, because the
     * return screen owes this person a new link rather than a sign-in form: nobody
     * mistyped anything, the link simply outlived its moment.
     */
    public const string CODE_MAGIC_LINK_INVALID = 'magic_link_invalid';

    /** Too many codes went to this address inside the window: the surface stays put and says so. */
    public const string CODE_SEND_CAP_REACHED = 'send_cap_reached';

    /** No recovery code is live for the address any more: the surface rolls back to the identifier step. */
    public const string CODE_RESET_CODE_EXPIRED = 'reset_code_expired';

    /** Another session finished the recovery this one was on: the password is already the new one. */
    public const string CODE_PASSWORD_ALREADY_CHANGED = 'password_already_changed';

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

    /** Wire key for the moment the resend gate opens. */
    private const string FIELD_RESEND_AT = 'resendAt';

    /**
     * Wire key for the moment the code or link now in play stops working (HIL-486).
     *
     * What the waiting screen counts down. It describes the LIVE challenge and not
     * the send that just happened, which is why a send the cooldown swallowed still
     * answers one: the person is looking at a code that is still good, and the
     * screen owes them its real remaining life rather than a fresh full one.
     *
     * It says nothing about whether an account exists, deliberately. Every flow that
     * reaches a waiting screen issues a challenge on both sides of that question, so
     * a countdown that appeared for a stranger and not for a member would turn the
     * magic-link screen's carefully generic sentence into an oracle.
     */
    private const string FIELD_EXPIRES_AT = 'expiresAt';

    /**
     * @param bool $ok Whether the submit succeeded
     * @param ?string $step Step the surface moves to, or null to stay put
     * @param ?string $intent Intent the surface moves to, or null to keep the current one
     * @param ?string $code Semantic error code on failure, or null
     * @param ?string $message Backend-authored inline message on failure, or null
     * @param ?int $resendAt Server moment a code re-send is allowed, in epoch ms, or null when nothing was sent
     * @param ?int $expiresAt Server moment the code or link in play dies, in epoch ms, or null when none is
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $step,
        public readonly ?string $intent,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?int $resendAt,
        public readonly ?int $expiresAt,
    ) {
    }

    /**
     * Builds a successful outcome that moves the surface to a step.
     *
     * @param string $step Step the surface moves to (see AuthFlowStep)
     * @param string $intent Intent the surface moves under (see AuthFlowIntent)
     * @param ?int $resendAt Server moment a re-send is allowed, in epoch ms, or null when no code is in play
     * @param ?int $expiresAt Server moment the code in play dies, in epoch ms, or null when none is
     * @return static Success outcome
     */
    public static function moveTo(
        string $step,
        string $intent,
        ?int $resendAt = null,
        ?int $expiresAt = null,
    ): static {
        return new static(true, $step, $intent, null, null, $resendAt, $expiresAt);
    }

    /**
     * Builds a successful outcome that only reports a send, moving nowhere.
     *
     * The shape of the resend actions (HIL-421): the person is already on the screen
     * the code belongs to, so there is nothing to move to - the only news is when the
     * button comes back. A send held back by the cooldown answers this too, with the
     * moment that gate opens, because a repeat pressed too soon is a countdown and not
     * an error.
     *
     * @param int $resendAt Server moment a re-send is allowed, in epoch ms
     * @param ?int $expiresAt Server moment the code or link in play dies, in epoch ms, or null when none is
     * @return static Success outcome carrying only the resend gate
     */
    public static function sent(int $resendAt, ?int $expiresAt = null): static
    {
        return new static(true, null, null, null, null, $resendAt, $expiresAt);
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
        return new static(false, $step, $intent, $code, $message, null, null);
    }

    /**
     * Builds a failed outcome that moves the surface nowhere.
     *
     * The sibling of {@see rejectTo()} for a refusal with no better place to be: the
     * send cap (HIL-421) leaves the person exactly where they are, holding a sentence
     * instead of a countdown. It carries no moment on purpose - the core arms its
     * gate off `resendAt` and would otherwise show a dead timer for a button
     * that is not coming back this window.
     *
     * @param string $code Semantic error code (see self::CODE_*)
     * @param string $message Backend-authored inline sentence
     * @return static Failure outcome that stays put
     */
    public static function refuse(string $code, string $message): static
    {
        return new static(false, null, null, $code, $message, null, null);
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
        if ($this->resendAt !== null) {
            $data[self::FIELD_RESEND_AT] = $this->resendAt;
        }
        if ($this->expiresAt !== null) {
            $data[self::FIELD_EXPIRES_AT] = $this->expiresAt;
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
            self::optionalInt($data, self::FIELD_RESEND_AT),
            self::optionalInt($data, self::FIELD_EXPIRES_AT),
        );
    }
}

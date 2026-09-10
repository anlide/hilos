<?php

declare(strict_types=1);

namespace Hilos\Auth\Detection;

use Hilos\Auth\AuthMethodKey;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * IdentifierDetection - what a live identifier lookup answers the surface with (HIL-414).
 *
 * The backend half of `IdentifierDetection` in `@hilos/core` (`auth/authFlow.ts`):
 * the surface cannot know whether a typed identifier means sign-in, registration or
 * a parked code screen, and this is how it is told. It rides the action's success
 * ack as the domain reply ({@see ActionReplyDTO}), so no lookup signal exists.
 *
 * Two things about the shape are load-bearing. The identifier is echoed VERBATIM
 * next to its normalized form, because the surface matches a reply to the field by
 * what it asked - normalizing a phone to E.164 would otherwise orphan the answer
 * that phone's own keystroke asked for. And the four statuses carry different
 * slots, which is why the constructor is private and each status has its own
 * factory: `pending` and `proven` say nothing about methods (the surface goes
 * straight to the step they name), `active` names what the account can sign in
 * with, `none` names what it could be registered with. A detection with both
 * filled in does not exist and cannot be built here.
 *
 * `proven` is a state of its own rather than a flag inside `pending` (HIL-825).
 * `pending` means "go to the code screen" in five places of the sign-in machine,
 * and a sub-field would have obliged every one of them to learn a second meaning;
 * one place left unchanged would send somebody who has already proved their
 * address back to type a code that is already spent.
 *
 * `kind` has no `unknown`: an identifier that classifies as neither an address nor
 * a number is a validation error of the action, not a detection result.
 *
 * A `none` that registers nobody also says WHY (HIL-830). An empty `registerable`
 * used to mean two different things at once - a project that closed registration on
 * purpose, and a deployment that simply has no way to send a code - and the surface,
 * seeing only the empty list, blamed a decision nobody had taken. The reason is
 * resolved here, on the side that knows both facts, and never by the surface
 * comparing flags. It rides `none` alone: a held or owned identifier is not up for
 * registration at all, and a slot that was always null there would eventually be read
 * as "cause unknown".
 */
final class IdentifierDetection extends ActionReplyDTO
{
    /** The identifier classified as an email address. */
    public const string KIND_EMAIL = 'email';

    /** The identifier classified as a phone number. */
    public const string KIND_PHONE = 'phone';

    /** No account and no live registration hold: the identifier is free. */
    public const string STATUS_NONE = 'none';

    /** A registration hold whose code is already out: the surface parks on the code step. */
    public const string STATUS_PENDING = 'pending';

    /** A registration hold this browser has already proved: the surface goes to the password step. */
    public const string STATUS_PROVEN = 'proven';

    /** An account exists behind the identifier: the surface signs in. */
    public const string STATUS_ACTIVE = 'active';

    /** Registration is not offered because the project enabled no way to register. */
    public const string BLOCK_CLOSED = 'closed';

    /** Registration is not offered because this installation cannot deliver a code. */
    public const string BLOCK_NO_CHANNEL = 'no_channel';

    /** Wire key for the verbatim echo of the looked-up identifier. */
    private const string FIELD_IDENTIFIER = 'identifier';

    /** Wire key for the backend-normalized identifier. */
    private const string FIELD_NORMALIZED = 'normalized';

    /** Wire key for the identifier classification. */
    private const string FIELD_KIND = 'kind';

    /** Wire key for the account status. */
    private const string FIELD_STATUS = 'status';

    /** Wire key for the method keys an existing account signs in with. */
    private const string FIELD_METHODS = 'methods';

    /** Wire key for the method keys registration is open with. */
    private const string FIELD_REGISTERABLE = 'registerable';

    /** Wire key for why registration is not offered on a free identifier. */
    private const string FIELD_REGISTRATION_BLOCK = 'registrationBlock';

    /**
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @param string $status Account status (see self::STATUS_*)
     * @param list<string> $methods Method keys of the existing account (see AuthMethodKey)
     * @param list<string> $registerable Method keys registration is open with (see AuthMethodKey)
     * @param ?string $registrationBlock Why registration is not offered (see self::BLOCK_*), or null when it is
     */
    private function __construct(
        public readonly string $identifier,
        public readonly string $normalized,
        public readonly string $kind,
        public readonly string $status,
        public readonly array $methods,
        public readonly array $registerable,
        public readonly ?string $registrationBlock = null,
    ) {
    }

    /**
     * Builds the answer for a free identifier: nothing holds it and nobody owns it.
     *
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @param list<string> $registerable Method keys registration is open with (see AuthMethodKey)
     * @param ?string $registrationBlock Why registration is not offered (see self::BLOCK_*), or null when it is
     * @return static Detection with status `none`
     */
    public static function free(
        string $identifier,
        string $normalized,
        string $kind,
        array $registerable,
        ?string $registrationBlock = null,
    ): static {
        return new static($identifier, $normalized, $kind, self::STATUS_NONE, [], $registerable, $registrationBlock);
    }

    /**
     * Builds the answer for an identifier held by a registration awaiting its code.
     *
     * Neither method list is asked for: the surface goes straight to the code step,
     * and offering it a way in or a way to register would contradict the hold.
     *
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @return static Detection with status `pending`
     */
    public static function held(string $identifier, string $normalized, string $kind): static
    {
        return new static($identifier, $normalized, $kind, self::STATUS_PENDING, [], []);
    }

    /**
     * Builds the answer for an identifier this browser's hold has already proved (HIL-825).
     *
     * Carries neither method list, for the reason {@see held()} carries neither: the
     * surface goes straight to the password step, and the account this address will get
     * does not exist yet, so there is nothing to name a way into. It is only ever built
     * for the ASKING browser's own hold - somebody else's proof leaves the address free
     * to everyone, and the race is settled by whoever saves a password first.
     *
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @return static Detection with status `proven`
     */
    public static function proven(string $identifier, string $normalized, string $kind): static
    {
        return new static($identifier, $normalized, $kind, self::STATUS_PROVEN, [], []);
    }

    /**
     * Builds the answer for an identifier that already belongs to an account.
     *
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @param list<string> $methods Method keys the account signs in with (see AuthMethodKey)
     * @return static Detection with status `active`
     */
    public static function owned(string $identifier, string $normalized, string $kind, array $methods): static
    {
        return new static($identifier, $normalized, $kind, self::STATUS_ACTIVE, $methods, []);
    }

    /**
     * @return array{
     *     identifier: string,
     *     normalized: string,
     *     kind: string,
     *     status: string,
     *     methods: list<string>,
     *     registerable: list<string>,
     *     registrationBlock: ?string,
     * } Wire form; both lists and the block are always present, empty or null where the status has none
     */
    public function toArray(): array
    {
        return [
            self::FIELD_IDENTIFIER => $this->identifier,
            self::FIELD_NORMALIZED => $this->normalized,
            self::FIELD_KIND => $this->kind,
            self::FIELD_STATUS => $this->status,
            self::FIELD_METHODS => $this->methods,
            self::FIELD_REGISTERABLE => $this->registerable,
            self::FIELD_REGISTRATION_BLOCK => $this->registrationBlock,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form of a detection
     * @return static Restored detection
     * @throws InvalidFormatException When a field is absent, of another type, or a list holds a non-string
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::FIELD_IDENTIFIER),
            self::requireString($data, self::FIELD_NORMALIZED),
            self::requireString($data, self::FIELD_KIND),
            self::requireString($data, self::FIELD_STATUS),
            self::requireMethodKeys($data, self::FIELD_METHODS),
            self::requireMethodKeys($data, self::FIELD_REGISTERABLE),
            self::optionalString($data, self::FIELD_REGISTRATION_BLOCK),
        );
    }

    /**
     * Reads one of the two method-key lists off the wire form.
     *
     * @param array<string, mixed> $data Wire form of a detection
     * @param string $key Wire key of the list to read
     * @return list<string> Method keys (see AuthMethodKey)
     * @throws InvalidFormatException When the field is absent, not an array, or holds a non-string
     */
    private static function requireMethodKeys(array $data, string $key): array
    {
        $keys = [];
        foreach (self::requireArray($data, $key) as $methodKey) {
            if (!is_string($methodKey)) {
                throw new InvalidFormatException('Field ' . $key . ' must hold ' . AuthMethodKey::class . ' strings');
            }
            $keys[] = $methodKey;
        }

        return $keys;
    }
}

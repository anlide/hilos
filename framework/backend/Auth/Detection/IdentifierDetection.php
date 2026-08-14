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
 * that phone's own keystroke asked for. And the three statuses carry different
 * slots, which is why the constructor is private and each status has its own
 * factory: `pending` says nothing about methods (the surface parks on the code
 * screen without reading them), `active` names what the account can sign in with,
 * `none` names what it could be registered with. A detection with both filled in
 * does not exist and cannot be built here.
 *
 * `kind` has no `unknown`: an identifier that classifies as neither an address nor
 * a number is a validation error of the action, not a detection result.
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

    /** An account exists behind the identifier: the surface signs in. */
    public const string STATUS_ACTIVE = 'active';

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

    /**
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @param string $status Account status (see self::STATUS_*)
     * @param list<string> $methods Method keys of the existing account (see AuthMethodKey)
     * @param list<string> $registerable Method keys registration is open with (see AuthMethodKey)
     */
    private function __construct(
        public readonly string $identifier,
        public readonly string $normalized,
        public readonly string $kind,
        public readonly string $status,
        public readonly array $methods,
        public readonly array $registerable,
    ) {
    }

    /**
     * Builds the answer for a free identifier: nothing holds it and nobody owns it.
     *
     * @param string $identifier Identifier exactly as it was submitted
     * @param string $normalized Identifier in its canonical form
     * @param string $kind Classification (see self::KIND_*)
     * @param list<string> $registerable Method keys registration is open with (see AuthMethodKey)
     * @return static Detection with status `none`
     */
    public static function free(string $identifier, string $normalized, string $kind, array $registerable): static
    {
        return new static($identifier, $normalized, $kind, self::STATUS_NONE, [], $registerable);
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
     * } Wire form; both lists are always present, empty where the status has none
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

<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\Auth\Flow\DTO\AuthConvergeSignalData;
use Hilos\Auth\Method\DTO\AuthMethodsSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionAck;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * HandshakeResponseSignalData - Signal data for the session handshake response.
 *
 * Framework-owned (HIL-361): the payload is entirely session-generic, so every
 * project reuses it and resolves the display names through its own
 * `handshakeResponseFor(session)` hook. Carries the session-scope payload in the
 * `{entities: {currentUser: {...}, impersonatedBy: null|{...}}}` wire form: the
 * frontend normalizer upserts the current-user (and, when impersonating, the
 * impersonating admin) entity fragment into the session entity store and places
 * the references in the session data store. The `impersonatedBy` slot is the
 * single source the frontend derives `impersonating` from (non-null ⇒ the
 * session is being impersonated), symmetric with `currentUser`: null clears it.
 * Display name updates, page snapshots, and session fields are sent through
 * browser rows after page subscription.
 * Target client ID is handled by WebSocketSignalData wrapper for routing.
 *
 * Alongside the entities travels the plain `{data: {pendingAck: ...}}` section
 * (HIL-422): the ack the receiving CONNECTION still owes its person, or null when it
 * owes none. The key is written on every response rather than only when set, because
 * the frontend derives the surface from it — an omitted key would read as "unchanged"
 * where the clearing is the whole message. It is per-connection, so one broadcast of
 * the same identity carries a different value to each socket of the session.
 *
 * The same section carries the session context every socket is told (HIL-486):
 * `serverTimeMs`, the server's own "now", and `pendingAuthStep`, the authentication
 * step this session stands on and has not finished. The step node names its own flow
 * (HIL-648): `intent` says whether the session is registering or recovering a
 * password, `step` says which screen of that flow it stands on, and the remaining
 * members describe the address the flow runs against. One node covers both flows
 * because a session cannot stand in two of them at once, so a fresh tab reads its
 * screen from a single key instead of guessing which of several was written.
 *
 * The node names a REASON as well (HIL-833): `code` says why the session stands where
 * it does, in the same vocabulary {@see AuthConvergeSignalData} pushes to a connection
 * that is on the wire. The two doors then carry one triple — step, intent, reason — and
 * the surface takes them apart on one path instead of two that resemble each other. It
 * is what lets the handshake tell a browser something it MISSED rather than only where
 * it left off: a session whose address was registered by somebody else while it was
 * asleep comes back to the identifier field knowing the address is taken, instead of to
 * a code screen for a registration that has quietly stopped being winnable. `expiresAt`
 * is nullable for the same reason — that step counts down to nothing, and there is no
 * moment to promise.
 * Server time rides the handshake because the browser clock is not evidence — a
 * countdown drawn against it runs out early or never — so the client measures the
 * offset once per handshake and reads every absolute moment the backend sends
 * against that. Both keys are written for an anonymous session too: an anonymous
 * session is exactly the one that may be halfway through such a flow, and it needs
 * the clock to draw that step's expiry.
 *
 * `codeDelivery` joins them (HIL-830): whether this installation can deliver a
 * one-time code to an email address and whether it can deliver one to a phone number.
 * It rides the handshake because the surface has to know it BEFORE anything is typed
 * — a deployment with nothing to send with should decline to offer registration
 * rather than walk somebody to a code screen for a letter that cannot be sent — and
 * because it is derived from env and the code-channel registry, neither of which
 * changes under a live process, so a fresh connection learns a new truth by itself and
 * no "configuration changed" signal exists. It reaches an anonymous session, which is
 * the only one it concerns, for the same reason the auth step does. A response that
 * carries it as null is one that never passed the framework's stamp; the surface reads
 * that as "everything is deliverable", which is what every deployment did before the
 * key existed.
 *
 * `authMethods` is the installation's enabled sign-in methods (HIL-427), in the order
 * the surface draws them, each with the name a provider's button shows. It rides the
 * handshake for the reason `codeDelivery` does - the surface has to know it before
 * anything is typed - but unlike it the set DOES change under a live process: an
 * administrator switches a method, and the settings library sends the new set to every
 * connection on its own frame. The handshake gives a new connection the set as it is
 * now; the frame keeps the old ones in step. Null means the stamp never ran.
 *
 * `passkeyAllowsUnproven` rides beside it (HIL-1105): whether a passkey may start an account
 * on an unconfirmed address. It is read together with the set and travels on the same frame
 * after a change, so a new connection gets the two as they stand together. Null means the
 * stamp never ran; the surface reads that, and an absent key, as no.
 *
 * The step node also describes a sign-in held on its SECOND FACTOR (HIL-494): step
 * `second_factor` or `second_factor_setup` under the sign-in intent, `expiresAt` the moment
 * the held sign-in runs out, and a `secondFactor` member with what the code screen needs -
 * how many days the device checkbox promises and when an already asked removal takes effect.
 * Such a step names no address - the person proved one on the way in and the screen does
 * not repeat it - so `identifier` and `kind` are null on it and only on it; every other
 * step still names both.
 *
 * `accountBlocked` is the "Access closed" card (HIL-289): the blocked account this browser lost
 * or was refused, named by its confirmed address - `{identifier: null}` when it has none - or
 * null when the session holds no card. It rides every response, the anonymous one above all,
 * because the browser that lost an account is anonymous by then; a response carrying null takes
 * the card down, so a stamp that forgot it would too, which is why the framework stamps it
 * ({@see withAccountBlocked()}) rather than the project.
 */
final class HandshakeResponseSignalData extends BaseDTO implements SignalDataInterface
{
    public const string entities = 'entities';
    public const string currentUser = 'currentUser';
    public const string impersonatedBy = 'impersonatedBy';
    public const string id = 'id';
    public const string name = 'name';
    public const string admin = 'admin';
    public const string data = 'data';
    public const string pendingAck = 'pendingAck';
    public const string serverTimeMs = 'serverTimeMs';
    public const string pendingAuthStep = 'pendingAuthStep';
    public const string codeDelivery = 'codeDelivery';
    public const string authMethods = 'authMethods';
    public const string passkeyAllowsUnproven = 'passkeyAllowsUnproven';
    public const string email = 'email';
    public const string phone = 'phone';
    public const string identifier = 'identifier';
    public const string kind = 'kind';
    public const string intent = 'intent';
    public const string step = 'step';
    public const string channel = 'channel';
    public const string expiresAt = 'expiresAt';
    public const string code = 'code';
    public const string secondFactor = 'secondFactor';
    public const string trustDeviceDays = 'trustDeviceDays';
    public const string resetEffectiveAt = 'resetEffectiveAt';
    public const string accountBlocked = 'accountBlocked';

    /**
     * Creates handshake response signal data.
     *
     * The current-user fields are null for an anonymous session, which clears the
     * frontend current user; an authenticated session carries the durable user id
     * and name. The impersonator fields are null unless the session is being
     * impersonated, in which case they carry the admin behind the impersonation.
     *
     * The admin flag travels with the identity because the shell decides what to
     * show from it — the admin entry is drawn for an admin and for nobody else.
     * It is false for an anonymous session and for a project that answers no
     * admin identity, so a shell that was told nothing shows no admin entry:
     * the same fail-closed default the page access gate takes.
     *
     * @param ?int $selfId Authenticated user id, or null when the session is anonymous
     * @param ?string $selfName Authenticated user display name, or null when anonymous
     * @param bool $selfAdmin Whether the authenticated user holds the admin privilege
     * @param ?int $impersonatorId Impersonating admin's user id, or null when not impersonating
     * @param ?string $impersonatorName Impersonating admin's display name, or null when not impersonating
     * @param ?string $pendingAck Ack the receiving connection still owes (a {@see SessionAck} value), or null
     * @param ?int $serverTimeMs Server "now" in epoch milliseconds, or null before the session context is stamped
     * @param ?array{identifier: ?string, kind: ?string, intent: string, step: string,
     *     channel: ?string, expiresAt: ?int, code: ?string,
     *     secondFactor?: array{trustDeviceDays: ?int, resetEffectiveAt: ?int}} $pendingAuthStep
     *     Authentication step the session stands on, or null when it stands on none
     * @param ?array{email: bool, phone: bool} $codeDelivery What this installation can deliver a one-time
     *     code to, or null before the session context is stamped
     * @param ?list<array{key: string, name: ?string, ready: bool}> $authMethods Enabled sign-in methods in button order,
     *     or null before the session context is stamped
     * @param ?bool $passkeyAllowsUnproven Whether a passkey may start an account on an unconfirmed address,
     *     or null before the session context is stamped
     * @param ?array{identifier: ?string} $accountBlocked Blocked account the session lost, or null when it holds no card
     */
    public function __construct(
        public readonly ?int $selfId = null,
        public readonly ?string $selfName = null,
        public readonly bool $selfAdmin = false,
        public readonly ?int $impersonatorId = null,
        public readonly ?string $impersonatorName = null,
        public readonly ?string $pendingAck = null,
        public readonly ?int $serverTimeMs = null,
        public readonly ?array $pendingAuthStep = null,
        public readonly ?array $codeDelivery = null,
        public readonly ?array $authMethods = null,
        public readonly ?bool $passkeyAllowsUnproven = null,
        public readonly ?array $accountBlocked = null,
    ) {
    }

    /**
     * Returns the same identity addressed to a connection that owes a different ack.
     *
     * The identity half of the response is built once per session — one user, one
     * name, one impersonator — while the ack half belongs to a single socket. A
     * broadcast therefore builds the response once and re-addresses it per connection
     * through this, instead of resolving the user again for every tab.
     *
     * @param ?string $pendingAck Ack the addressed connection owes (a {@see SessionAck} value), or null for none
     * @return self The same response carrying that ack
     */
    public function withPendingAck(?string $pendingAck): self
    {
        return new self(
            selfId: $this->selfId,
            selfName: $this->selfName,
            selfAdmin: $this->selfAdmin,
            impersonatorId: $this->impersonatorId,
            impersonatorName: $this->impersonatorName,
            pendingAck: $pendingAck,
            serverTimeMs: $this->serverTimeMs,
            pendingAuthStep: $this->pendingAuthStep,
            codeDelivery: $this->codeDelivery,
            authMethods: $this->authMethods,
            passkeyAllowsUnproven: $this->passkeyAllowsUnproven,
            accountBlocked: $this->accountBlocked,
        );
    }

    /**
     * Returns the same response carrying the "Access closed" card the session holds (HIL-289).
     *
     * The third axis beside {@see withPendingAck()} and {@see withSessionContext()}: the card is a
     * mark on the session row, which the project does not read, so it arrives on the state frame
     * and the framework stamps it here on every send path.
     *
     * @param ?array{identifier: ?string} $accountBlocked Blocked account the session lost, or null when it holds no card
     * @return self The same response carrying that card
     */
    public function withAccountBlocked(?array $accountBlocked): self
    {
        return new self(
            selfId: $this->selfId,
            selfName: $this->selfName,
            selfAdmin: $this->selfAdmin,
            impersonatorId: $this->impersonatorId,
            impersonatorName: $this->impersonatorName,
            pendingAck: $this->pendingAck,
            serverTimeMs: $this->serverTimeMs,
            pendingAuthStep: $this->pendingAuthStep,
            codeDelivery: $this->codeDelivery,
            authMethods: $this->authMethods,
            passkeyAllowsUnproven: $this->passkeyAllowsUnproven,
            accountBlocked: $accountBlocked,
        );
    }

    /**
     * Returns the same response stamped with the session context (HIL-486).
     *
     * The twin of {@see withPendingAck()} on the other axis: the identity half is
     * built by the project hook, which knows the user store and nothing about the
     * clock or the auth flows, so the framework stamps those onto whatever
     * the project answered. Every send path goes through it — a response that left
     * either key out would silently keep the browser on the offset and the step it
     * happened to hold, which is exactly what a re-handshake exists to refresh.
     *
     * @param int $serverTimeMs Server "now" in epoch milliseconds
     * @param ?array{identifier: ?string, kind: ?string, intent: string, step: string,
     *     channel: ?string, expiresAt: ?int, code: ?string,
     *     secondFactor?: array{trustDeviceDays: ?int, resetEffectiveAt: ?int}} $pendingAuthStep
     *     Authentication step the session stands on, or null when it stands on none
     * @param array{email: bool, phone: bool} $codeDelivery What this installation can deliver a one-time code to
     * @param list<array{key: string, name: ?string, ready: bool}> $authMethods Enabled sign-in methods in button order
     * @param bool $passkeyAllowsUnproven Whether a passkey may start an account on an unconfirmed address
     * @return self The same response carrying that session context
     */
    public function withSessionContext(
        int $serverTimeMs,
        ?array $pendingAuthStep,
        array $codeDelivery,
        array $authMethods,
        bool $passkeyAllowsUnproven,
    ): self {
        return new self(
            selfId: $this->selfId,
            selfName: $this->selfName,
            selfAdmin: $this->selfAdmin,
            impersonatorId: $this->impersonatorId,
            impersonatorName: $this->impersonatorName,
            pendingAck: $this->pendingAck,
            serverTimeMs: $serverTimeMs,
            pendingAuthStep: $pendingAuthStep,
            codeDelivery: $codeDelivery,
            authMethods: $authMethods,
            passkeyAllowsUnproven: $passkeyAllowsUnproven,
            accountBlocked: $this->accountBlocked,
        );
    }

    /**
     * Convert DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::entities => [
                self::currentUser => $this->selfId === null
                    ? null
                    : [
                        self::id => $this->selfId,
                        self::name => $this->selfName,
                        self::admin => $this->selfAdmin,
                    ],
                self::impersonatedBy => $this->impersonatorId === null
                    ? null
                    : [
                        self::id => $this->impersonatorId,
                        self::name => $this->impersonatorName,
                    ],
            ],
            self::data => [
                self::pendingAck => $this->pendingAck,
                self::serverTimeMs => $this->serverTimeMs,
                self::pendingAuthStep => $this->pendingAuthStep,
                self::codeDelivery => $this->codeDelivery,
                self::authMethods => $this->authMethods,
                self::passkeyAllowsUnproven => $this->passkeyAllowsUnproven,
                self::accountBlocked => $this->accountBlocked,
            ],
        ];
    }

    /**
     * Create DTO from wire payload.
     *
     * An anonymous session is written as a null current-user node, so a payload
     * without one still builds the empty response a guest gets - that absence is
     * the contract, not a gap. What a present node may not do is arrive without
     * the fields that make it an identity: the id and the admin flag are required
     * inside it, and the display name is the one field a project is allowed to
     * answer as null. The impersonator node is read the same way when it is there.
     *
     * The ack is read on both branches: a response can clear the current user and
     * still owe the socket a sentence, and dropping it on the anonymous branch would
     * make the round trip lossy for exactly the payload the logout path sends. The
     * session context is read on both for the stronger reason: the session halfway
     * through registration or password recovery is anonymous by definition, so the
     * anonymous branch is the one that carries it.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When a present identity or auth-step node lacks a required field
     */
    public static function fromArray(array $data): static
    {
        $entities = self::optionalArray($data, self::entities) ?? [];
        $currentUser = self::optionalArray($entities, self::currentUser);
        $impersonatedBy = self::optionalArray($entities, self::impersonatedBy);
        $section = self::optionalArray($data, self::data) ?? [];
        $pendingAck = self::optionalString($section, self::pendingAck);
        $serverTimeMs = self::optionalInt($section, self::serverTimeMs);
        $pendingAuthStep = self::readPendingAuthStep($section);
        $codeDelivery = self::readCodeDelivery($section);
        $authMethods = self::readAuthMethods($section);
        $passkeyAllowsUnproven = self::optionalBool($section, self::passkeyAllowsUnproven);
        $accountBlocked = self::readAccountBlocked($section);
        if ($currentUser === null) {
            return new static(
                pendingAck: $pendingAck,
                serverTimeMs: $serverTimeMs,
                pendingAuthStep: $pendingAuthStep,
                codeDelivery: $codeDelivery,
                authMethods: $authMethods,
                passkeyAllowsUnproven: $passkeyAllowsUnproven,
                accountBlocked: $accountBlocked,
            );
        }

        return new static(
            selfId: self::requireInt($currentUser, self::id),
            selfName: self::optionalString($currentUser, self::name),
            selfAdmin: self::requireBool($currentUser, self::admin),
            impersonatorId: $impersonatedBy === null ? null : self::requireInt($impersonatedBy, self::id),
            impersonatorName: $impersonatedBy === null ? null : self::optionalString($impersonatedBy, self::name),
            pendingAck: $pendingAck,
            serverTimeMs: $serverTimeMs,
            pendingAuthStep: $pendingAuthStep,
            codeDelivery: $codeDelivery,
            authMethods: $authMethods,
            passkeyAllowsUnproven: $passkeyAllowsUnproven,
            accountBlocked: $accountBlocked,
        );
    }

    /**
     * Reads the "Access closed" card back into its declared shape (HIL-289).
     *
     * Shared with {@see SessionStateSignalData}, which carries the same node under the same key, so
     * the two ends of the seam cannot read it differently. A present node always comes back with its
     * one member, the address being optional: an account with no confirmed address is still a card.
     *
     * @param array<string, mixed> $section Map holding the node under `accountBlocked`
     * @return ?array{identifier: ?string} Node, or null when the session holds no card
     * @throws InvalidFormatException When the node or its address is not of the declared type
     */
    public static function readAccountBlocked(array $section): ?array
    {
        $node = self::optionalArray($section, self::accountBlocked);
        if ($node === null) {
            return null;
        }

        return [self::identifier => self::optionalString($node, self::identifier)];
    }

    /**
     * Reads the unfinished authentication step back into its declared shape.
     *
     * Rebuilt field by field rather than handed through as the map that arrived:
     * this is the parse boundary, and the node is the one part of the response the
     * surface navigates by — a member missing from it would surface as a code screen
     * with no identifier to name and no moment to count down to.
     *
     * Two of the seven are read as optional, and the pair is what the identifier step
     * is made of (HIL-833): a session told its address was taken while it was away
     * stands on no code and therefore on no expiry, so the moment is absent rather
     * than invented, and the reason is present rather than guessed from the step. The
     * strictness that used to live here moves to the surface, which knows which step it
     * is reading and can ask for the moment on exactly the two that count down.
     *
     * @param array<string, mixed> $section Plain data section of the response
     * The address is optional since HIL-494: a sign-in held on its second factor names none,
     * and neither does the address field it is sent back to when the hold ends. The second-factor
     * member is read only where it came.
     *
     * @return ?array{identifier: ?string, kind: ?string, intent: string, step: string,
     *     channel: ?string, expiresAt: ?int, code: ?string,
     *     secondFactor?: array{trustDeviceDays: ?int, resetEffectiveAt: ?int}} Node, or null when absent
     * @throws InvalidFormatException When a present node lacks a required member
     */
    private static function readPendingAuthStep(array $section): ?array
    {
        $node = self::optionalArray($section, self::pendingAuthStep);
        if ($node === null) {
            return null;
        }

        $step = [
            self::identifier => self::optionalString($node, self::identifier),
            self::kind => self::optionalString($node, self::kind),
            self::intent => self::requireString($node, self::intent),
            self::step => self::requireString($node, self::step),
            self::channel => self::optionalString($node, self::channel),
            self::expiresAt => self::optionalInt($node, self::expiresAt),
            self::code => self::optionalString($node, self::code),
        ];
        $secondFactor = self::optionalArray($node, self::secondFactor);
        if ($secondFactor !== null) {
            $step[self::secondFactor] = [
                self::trustDeviceDays => self::optionalInt($secondFactor, self::trustDeviceDays),
                self::resetEffectiveAt => self::optionalInt($secondFactor, self::resetEffectiveAt),
            ];
        }

        return $step;
    }

    /**
     * Reads what this installation can deliver a one-time code to.
     *
     * An absent node stays absent rather than becoming a pair of flags: this is the
     * parse boundary and it reports what arrived. The reading that turns a missing
     * answer into "everything is deliverable" belongs to the surface, which is the
     * only place that knows what to do with not being told (HIL-830).
     *
     * @param array<string, mixed> $section Plain data section of the response
     * @return ?array{email: bool, phone: bool} Node, or null when absent
     * @throws InvalidFormatException When a present node lacks a flag or holds a non-boolean
     */
    private static function readCodeDelivery(array $section): ?array
    {
        $node = self::optionalArray($section, self::codeDelivery);
        if ($node === null) {
            return null;
        }

        return [
            self::email => self::requireBool($node, self::email),
            self::phone => self::requireBool($node, self::phone),
        ];
    }

    /**
     * Reads the enabled sign-in methods back into their declared shape.
     *
     * Absent stays absent, as {@see readCodeDelivery()} keeps it; a present list is read
     * entry by entry the way the frame that later replaces it is ({@see AuthMethodsSignalData}).
     *
     * @param array<string, mixed> $section Plain data section of the response
     * @return ?list<array{key: string, name: ?string, ready: bool}> Methods in button order, or null when absent
     * @throws InvalidFormatException When a present entry is not a map or lacks its key
     */
    private static function readAuthMethods(array $section): ?array
    {
        $node = self::optionalArray($section, self::authMethods);

        return $node === null ? null : AuthMethodsSignalData::entriesFrom($node);
    }
}
